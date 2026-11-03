#!/bin/bash
# Provision the fixed test account (authuserdb, Maildir) and run Courier's imapd via couriertcpd in the
# foreground (the Debian init script would daemonize it through courierlogger).
set -euo pipefail

user="${TEST_USER:-test}"
home="/var/vmail/$user"
if [ ! -d "$home/Maildir" ]; then
    mkdir -p "$home"
    maildirmake "$home/Maildir"
    chown -R vmail:vmail "$home"
fi
userdb "$user" set uid=5000 gid=5000 home="$home" mail="$home/Maildir"
echo "$TEST_PASSWORD" | userdbpw -md5 | userdb "$user" set systempw
makeuserdb

mkdir -p /run/courier/authdaemon
/usr/sbin/authdaemond start

set -a
. /etc/courier/imapd
set +a
exec /usr/sbin/couriertcpd -address=0 -maxprocs=200 -maxperip=200 -nodnslookup -noidentlookup \
    143 /usr/lib/courier/courier/imaplogin /usr/bin/imapd Maildir
