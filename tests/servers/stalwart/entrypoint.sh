#!/bin/bash
# Stalwart test server for the php-imap server matrix (tests/servers/docker-compose.yml).
#
# Stalwart >= 0.16 keeps almost all configuration in its database and is provisioned through its JMAP
# management API. /etc/stalwart and /var/lib/stalwart are tmpfs mounts, so every container start runs:
#   1. bootstrap mode (no config.json): x:Bootstrap/set writes config.json and returns the admin secret
#   2. normal start: create the test account, a cleartext IMAP listener on 143, allow cleartext login
#   3. restart (listeners are only bound at startup) and exec the server in the foreground
set -euo pipefail

CONFIG=/etc/stalwart/config.json
DOMAIN="${TEST_DOMAIN:-example.local}"
USER_NAME="${TEST_USER:-test}"
USER_PASSWORD="${TEST_PASSWORD:?TEST_PASSWORD missing}"
BOOTSTRAP_ADMIN="admin:bootstrap-$RANDOM$RANDOM"
USING='"using":["urn:ietf:params:jmap:core","urn:stalwart:jmap"]'

log() { echo "[stalwart-init] $*"; }

jmap() { # jmap <user:password> <methodCalls JSON>
    curl -sf -u "$1" -H 'Content-Type: application/json' http://127.0.0.1:8080/jmap \
        -d "{\"methodCalls\":$2,$USING}"
}

wait_http() {
    for _ in $(seq 1 120); do
        curl -s -o /dev/null http://127.0.0.1:8080/ && return 0
        sleep 0.5
    done
    log "management API did not come up"; exit 1
}

stop() { kill "$1"; wait "$1" || true; }

if [ ! -f "$CONFIG" ]; then
    log "bootstrapping"
    STALWART_RECOVERY_ADMIN="$BOOTSTRAP_ADMIN" /usr/local/bin/stalwart --config "$CONFIG" >/dev/null 2>&1 &
    pid=$!
    wait_http
    response=$(jmap "$BOOTSTRAP_ADMIN" "[[\"x:Bootstrap/set\",{\"update\":{\"singleton\":{
        \"serverHostname\":\"mail.$DOMAIN\",\"defaultDomain\":\"$DOMAIN\",
        \"requestTlsCertificate\":false,\"generateDkimKeys\":false}}},\"b\"]]")
    admin_secret=$(echo "$response" | sed -n 's/.*"secret":"\([^"]*\)".*/\1/p')
    [ -n "$admin_secret" ] || { log "bootstrap failed: $response"; exit 1; }
    stop "$pid"

    log "provisioning account $USER_NAME@$DOMAIN"
    /usr/local/bin/stalwart --config "$CONFIG" >/dev/null 2>&1 &
    pid=$!
    wait_http
    admin="admin@$DOMAIN:$admin_secret"
    domain_id=$(jmap "$admin" '[["x:Domain/get",{"ids":null},"d"]]' | sed -n 's/.*"id":"\([^"]*\)".*/\1/p')
    response=$(jmap "$admin" "[
        [\"x:Account/set\",{\"create\":{\"u\":{\"@type\":\"User\",\"name\":\"$USER_NAME\",\"domainId\":\"$domain_id\",
            \"credentials\":{\"0\":{\"@type\":\"Password\",\"secret\":\"$USER_PASSWORD\"}},
            \"roles\":{\"@type\":\"User\"},\"permissions\":{\"@type\":\"Inherit\"},
            \"encryptionAtRest\":{\"@type\":\"Disabled\"}}}},\"a\"],
        [\"x:NetworkListener/set\",{\"create\":{\"l\":{\"name\":\"imap\",\"bind\":{\"[::]:143\":true},
            \"protocol\":\"imap\",\"useTls\":true,\"tlsImplicit\":false}}},\"l\"],
        [\"x:Imap/set\",{\"update\":{\"singleton\":{\"allowPlainTextAuth\":true,\"maxConcurrent\":256,
            \"maxAuthFailures\":10}}},\"i\"]]")
    case "$response" in
        *notCreated*|*notUpdated*|"") log "provisioning failed: $response"; exit 1 ;;
    esac
    stop "$pid"
    log "done"
fi

exec /usr/local/bin/stalwart --config "$CONFIG"
