#!/bin/sh
# Matrix of real IMAP servers in Docker (tests/servers/docker-compose.yml) for live tests.
#
#   .claude/scripts/imap-servers.sh up [server…]          # start (all or some) servers + toxiproxy, waits for login
#   .claude/scripts/imap-servers.sh down                  # stop and remove everything (clean slate)
#   .claude/scripts/imap-servers.sh status                # containers, health, toxiproxy proxies
#   .claude/scripts/imap-servers.sh logs [server…]        # container logs (CI uploads them on failure)
#   .claude/scripts/imap-servers.sh reset [server…]       # recreate servers (empty mailboxes), all if none given
#   .claude/scripts/imap-servers.sh test <target|docker|a,b> [phpunit-args]
#                                                         # phpunit with IMAP_TARGETS (default: tests/live/ServerMatrixTest.php)
#   .claude/scripts/imap-servers.sh live <target> [--coverage] [phpunit-args]
#                                                         # tests/live + live tests of tests/issues against one target
#                                                         # (LIVE_MAILBOX_*, LIVE_MAILBOX_TARGET); docker targets are reset first
#   COVERAGE_DIR=build/cov-live-dovecot23 .claude/scripts/imap-servers.sh live dovecot23 --coverage
#   .claude/scripts/imap-servers.sh live dovecot23 --log-junit build/junit-dovecot23.xml
#   IMAP_SERVERS_BUILD=0 .claude/scripts/imap-servers.sh up cyrus   # use the existing cyrus/courier images (CI builds them with cache)
#
# Servers: dovecot23 dovecot24 greenmail cyrus stalwart james courier (targets: tests/servers/targets.php,
# dovecot23-ssl/dovecot24-ssl are the same containers via 993). Tests run in the php-imap-test container
# attached to the compose network php-imap-servers_default; nothing is published on the host.
set -e

cd "$(dirname "$0")/../.."
COMPOSE="docker compose -f tests/servers/docker-compose.yml"
NETWORK="php-imap-servers_default"
TOXIPROXY_API="${TOXIPROXY_API:-http://toxiproxy:8474}"
PHP_VERSION="${PHP_VERSION:-8.3}"
IMAGE="php-imap-test:$PHP_VERSION"
BUILD="--build"
[ "${IMAP_SERVERS_BUILD:-1}" = "0" ] && BUILD=""

usage() {
    sed -n '2,/^set -e/{/^#/p}' "$0" | sed 's/^# \{0,1\}//'
    exit 1
}

# Test image and vendor/ (as phpunit.sh does) - live_env needs them before phpunit.sh runs
prepare_php() {
    if ! docker image inspect "$IMAGE" >/dev/null 2>&1; then
        docker build -q -f tests/docker/php.Dockerfile --build-arg PHP_VERSION="$PHP_VERSION" -t "$IMAGE" tests/docker >/dev/null
    fi
    if [ ! -f vendor/autoload.php ]; then
        docker run --rm -u "$(id -u):$(id -g)" -v "$PWD":/app -w /app "$IMAGE" composer install -q --no-interaction
    fi
}

# Print the LIVE_MAILBOX_* variables of a target as env-file lines (evaluated in the test image, no local PHP)
live_env() {
    docker run --rm -u "$(id -u):$(id -g)" -v "$PWD":/app -w /app "$IMAGE" php -r '
        require "vendor/autoload.php";
        $t = Tests\support\ImapTargets::get($argv[1]);
        $env = [
            "LIVE_MAILBOX"                => "true",
            "LIVE_MAILBOX_HOST"           => $t["host"],
            "LIVE_MAILBOX_PORT"           => $t["port"],
            "LIVE_MAILBOX_ENCRYPTION"     => $t["encryption"] ?: "",
            "LIVE_MAILBOX_VALIDATE_CERT"  => $t["validate_cert"] ? "1" : "",
            "LIVE_MAILBOX_USERNAME"       => $t["username"],
            "LIVE_MAILBOX_PASSWORD"       => $t["password"],
            "LIVE_MAILBOX_AUTHENTICATION" => $t["authentication"] ?? "",
            "LIVE_MAILBOX_QUOTA_SUPPORT"  => Tests\support\ImapTargets::supports($argv[1], "quota") ? "1" : "",
            "LIVE_MAILBOX_DELIMITER"      => $t["delimiter"] ?? "/",
            "LIVE_MAILBOX_TARGET"         => $argv[1],
        ];
        foreach ($env as $k => $v) {
            echo "$k=$v\n";
        }
        if ($t["kind"] === "docker") {
            echo "# service=", $t["host"], "\n";
        }' "$1"
}

cmd="$1"
[ -n "$cmd" ] || usage
shift

case "$cmd" in
    up)
        if [ $# -gt 0 ]; then
            $COMPOSE up -d $BUILD --wait "$@" toxiproxy
        else
            $COMPOSE up -d $BUILD --wait
        fi
        ;;
    down)
        $COMPOSE down -v --remove-orphans
        ;;
    reset)
        $COMPOSE up -d $BUILD --force-recreate --renew-anon-volumes --wait "$@"
        ;;
    logs)
        $COMPOSE logs --no-color --timestamps "$@"
        ;;
    status)
        $COMPOSE ps --format 'table {{.Service}}\t{{.Image}}\t{{.Status}}'
        if $COMPOSE ps --status running -q toxiproxy 2>/dev/null | grep -q .; then
            echo
            $COMPOSE exec -T toxiproxy /toxiproxy-cli list
        fi
        ;;
    test)
        [ -n "$1" ] || usage
        targets="$1"
        shift
        coverage=""
        if [ "$1" = "--coverage" ]; then coverage="--coverage"; shift; fi
        [ $# -gt 0 ] || set -- tests/live/ServerMatrixTest.php
        DOCKER_ARGS="--network $NETWORK -e IMAP_TARGETS=$targets -e TOXIPROXY_API=$TOXIPROXY_API" \
            exec .claude/scripts/phpunit.sh $coverage "$@"
        ;;
    live)
        [ -n "$1" ] || usage
        target="$1"
        shift
        coverage=""
        if [ "$1" = "--coverage" ]; then coverage="--coverage"; shift; fi
        set -- -c tests/servers/phpunit-live.xml "$@"   # phpunit-args may add a path / --filter
        # env file instead of -e: DOCKER_ARGS is word-split by phpunit.sh, passwords may contain spaces
        mkdir -p build
        env_file="build/.imap-live-$target.env"
        prepare_php
        if ! live_env "$target" > "$env_file"; then
            cat "$env_file" >&2
            rm -f "$env_file"
            exit 1
        fi
        service=$(sed -n 's/^# service=//p' "$env_file")
        if [ -n "$service" ]; then
            if ! $COMPOSE up -d --force-recreate --renew-anon-volumes --wait "$service" toxiproxy >/dev/null 2>&1; then
                echo "Resetting $service failed:" >&2
                $COMPOSE ps -a "$service" toxiproxy >&2
                exit 1
            fi
        fi
        status=0
        DOCKER_ARGS="--network $NETWORK -e IMAP_TARGETS=$target -e TOXIPROXY_API=$TOXIPROXY_API --env-file $PWD/$env_file" \
            .claude/scripts/phpunit.sh $coverage "$@" || status=$?
        rm -f "$env_file"
        exit $status
        ;;
    *)
        usage
        ;;
esac
