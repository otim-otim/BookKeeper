# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# BookKeeper development / test image
#
# A package has no web server to run, so this image is a PHP CLI toolbox:
# Composer, Pest, and the PDO drivers needed to run the test suite against
# SQLite, MySQL and PostgreSQL.
# ---------------------------------------------------------------------------

ARG PHP_VERSION=8.5

FROM composer:2 AS composer

FROM php:${PHP_VERSION}-cli-alpine AS base

RUN apk add --no-cache \
        git \
        unzip \
        icu-libs \
        icu-data-full \
        libzip \
        postgresql-libs \
        sqlite-libs \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        icu-dev \
        libzip-dev \
        postgresql-dev \
        sqlite-dev \
        linux-headers \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        intl \
        pdo_mysql \
        pdo_pgsql \
        pdo_sqlite \
        zip \
    && pecl install pcov \
    && docker-php-ext-enable pcov \
    && apk del .build-deps

COPY --from=composer /usr/bin/composer /usr/bin/composer
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-bookkeeper.ini

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer

WORKDIR /app

# Install dependencies first so they are cached between source changes.
COPY composer.json composer.lock* ./
RUN composer install --no-interaction --no-progress --no-scripts --prefer-dist --no-autoloader

COPY . .
RUN composer dump-autoload --optimize

CMD ["vendor/bin/pest"]
