# Test runner image: PHP CLI with the extensions required by composer.json and pcov for code coverage.
# Build: docker build -f tests/docker/php.Dockerfile --build-arg PHP_VERSION=8.3 -t php-imap-test:8.3 tests/docker
# With ext-imap (bundled up to PHP 8.3, from PECL since 8.4; needs libc-client, which Debian trixie dropped):
#   --build-arg WITH_IMAP=1 --build-arg BASE_SUFFIX=-bookworm -t php-imap-test:8.3-imap
ARG PHP_VERSION=8.3
ARG BASE_SUFFIX=
FROM php:${PHP_VERSION}-cli${BASE_SUFFIX}
ARG WITH_IMAP=0

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libzip-dev \
    && docker-php-ext-install zip \
    && pecl install pcov \
    && docker-php-ext-enable pcov \
    && if [ "$WITH_IMAP" = "1" ]; then \
        apt-get install -y --no-install-recommends libc-client-dev libkrb5-dev \
        && if php -r 'exit(PHP_VERSION_ID >= 80400 ? 0 : 1);'; then \
            printf '\n' | pecl install imap && docker-php-ext-enable imap; \
        else \
            docker-php-ext-configure imap --with-kerberos --with-imap-ssl && docker-php-ext-install imap; \
        fi; \
    fi \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_HOME=/tmp/composer
WORKDIR /app
