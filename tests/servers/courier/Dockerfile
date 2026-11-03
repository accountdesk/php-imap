# Courier IMAP test server for the php-imap server matrix (tests/servers/docker-compose.yml).
# No maintained official image exists, so this builds one from the Debian package (Courier IMAP 5.0).
FROM debian:bookworm-20251020-slim

RUN apt-get update \
    && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
        courier-imap=5.0.13+1.0.16-3+b6 courier-authlib-userdb \
    && rm -rf /var/lib/apt/lists/* \
    && sed -i 's/^authmodulelist=.*/authmodulelist="authuserdb"/' /etc/courier/authdaemonrc \
    && groupadd -g 5000 vmail && useradd -u 5000 -g 5000 -d /var/vmail -m -s /usr/sbin/nologin vmail

COPY entrypoint.sh /entrypoint.sh

EXPOSE 143
ENTRYPOINT ["/entrypoint.sh"]
