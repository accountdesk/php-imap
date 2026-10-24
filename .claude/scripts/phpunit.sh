#!/bin/sh
# PHPUnit im Test-Image (tests/docker/php.Dockerfile, PHP + pcov) ausführen. Lokal ist kein PHP installiert.
#
#   .claude/scripts/phpunit.sh                                 # alle Tests (Live-Tests werden übersprungen)
#   .claude/scripts/phpunit.sh tests/IdleTest.php              # einzelne Datei / Verzeichnis
#   .claude/scripts/phpunit.sh --filter testName tests/IdleTest.php
#   .claude/scripts/phpunit.sh --coverage                      # + Coverage: build/coverage (HTML), build/coverage.txt
#   PHP_VERSION=8.0 .claude/scripts/phpunit.sh                 # andere PHP-Version (Image wird bei Bedarf gebaut)
#   COVERAGE_DIR=build/cov-x .claude/scripts/phpunit.sh --coverage   # eigenes Ausgabeverzeichnis
#   DOCKER_ARGS="--network x -e LIVE_MAILBOX=true" ...         # zusätzliche docker-run-Argumente
set -e

cd "$(dirname "$0")/../.."
PHP_VERSION="${PHP_VERSION:-8.3}"
COVERAGE_DIR="${COVERAGE_DIR:-build}"
IMAGE="php-imap-test:$PHP_VERSION"

if ! docker image inspect "$IMAGE" >/dev/null 2>&1; then
    docker build -q -f tests/docker/php.Dockerfile --build-arg PHP_VERSION="$PHP_VERSION" -t "$IMAGE" tests/docker >/dev/null
fi

# run <command> [args...] - args are passed through unchanged (no second round of shell parsing)
run() {
    cmd="$1"
    shift
    docker run --rm -u "$(id -u):$(id -g)" -v "$PWD":/app -w /app $DOCKER_ARGS "$IMAGE" sh -c "$cmd \"\$@\"" sh "$@"
}

if [ ! -f vendor/autoload.php ]; then
    run "composer install -q --no-interaction"
fi

coverage="--no-coverage"
if [ "$1" = "--coverage" ]; then
    shift
    coverage="--coverage-text=$COVERAGE_DIR/coverage.txt --coverage-html=$COVERAGE_DIR/coverage --coverage-clover=$COVERAGE_DIR/clover.xml --coverage-php=$COVERAGE_DIR/coverage.cov"
fi

status=0
run "php -d pcov.directory=/app/src vendor/bin/phpunit --no-logging $coverage" "$@" || status=$?
if [ "$coverage" != "--no-coverage" ]; then
    sed -n '1,12p' "$COVERAGE_DIR/coverage.txt" | grep -E "Summary|Classes|Methods|Lines" || true
fi
exit $status
