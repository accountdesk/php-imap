# Test runner image: PHP CLI with the extensions required by composer.json and pcov for code coverage.
# Build: docker build -f tests/docker/php.Dockerfile --build-arg PHP_VERSION=8.3 -t php-imap-test:8.3 tests/docker
ARG PHP_VERSION=8.3
FROM php:${PHP_VERSION}-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libzip-dev \
    && docker-php-ext-install zip \
    && pecl install pcov \
    && docker-php-ext-enable pcov \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_HOME=/tmp/composer
WORKDIR /app
