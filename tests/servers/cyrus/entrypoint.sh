#!/bin/bash
# Provision the fixed test account (sasldb; the INBOX is auto-created on first login, autocreate_quota)
# and run the Cyrus master process in the foreground.
set -euo pipefail

mkdir -p /run/cyrus/proc /run/cyrus/lock /run/cyrus/socket
chown -R cyrus:mail /run/cyrus
echo "$TEST_PASSWORD" | saslpasswd2 -p -c -u cyrus "${TEST_USER:-test}"
chown cyrus:mail /etc/sasldb2
chmod 0640 /etc/sasldb2
adduser cyrus ssl-cert >/dev/null 2>&1 || true

exec /usr/sbin/cyrmaster -D
