# Cyrus IMAP test server for the php-imap server matrix (tests/servers/docker-compose.yml).
# There is no maintained official Cyrus image, so this builds one from the Debian package (Cyrus 3.6).
FROM debian:bookworm-20251020-slim

RUN apt-get update \
    && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
        cyrus-imapd=3.6.1-4+deb12u5 cyrus-common=3.6.1-4+deb12u5 sasl2-bin libsasl2-modules ssl-cert openssl \
    && rm -rf /var/lib/apt/lists/*

COPY imapd.conf /etc/imapd.conf
COPY cyrus.conf /etc/cyrus.conf
COPY entrypoint.sh /entrypoint.sh

EXPOSE 143 993
ENTRYPOINT ["/entrypoint.sh"]
