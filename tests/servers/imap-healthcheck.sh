#!/bin/bash
# Healthcheck for the php-imap server matrix: succeeds only if LOGIN and SELECT INBOX work.
# Usage: imap-healthcheck.sh <host> <port> <username> <password>
# Plain IMAP only (bash /dev/tcp); commands are sent one at a time because some servers (James) reject
# commands pipelined behind LOGIN.
set -u
exec 3<>"/dev/tcp/$1/$2" || exit 1

expect() { # expect <tag>: read until the tagged response, succeed on OK
    local line
    while IFS= read -r -t 10 line <&3; do
        case "$line" in
            "$1 OK"*) return 0 ;;
            "$1 "*) echo "$line" >&2; return 1 ;;
        esac
    done
    return 1
}

IFS= read -r -t 10 greeting <&3 || exit 1
case "$greeting" in "* OK"*) ;; *) echo "$greeting" >&2; exit 1 ;; esac
printf 'h1 LOGIN "%s" "%s"\r\n' "$3" "$4" >&3
expect h1 || exit 1
printf 'h2 SELECT INBOX\r\n' >&3
expect h2 || exit 1
printf 'h3 LOGOUT\r\n' >&3
exit 0
