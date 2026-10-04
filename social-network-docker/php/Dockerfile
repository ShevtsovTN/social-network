# syntax=docker/dockerfile:1
# Один PHP-образ на оба приложения. Контекст сборки: корень репозитория.
#   target dev:   код монтируется через bind mount (docker-compose.override.yaml)
#   target bench: код копируется в образ, composer --no-dev --classmap-authoritative

# Версии образов закреплены для воспроизводимых замеров; обновлять осознанно, вместе с README.
ARG PHP_VERSION=8.4.26

FROM php:${PHP_VERSION}-fpm-alpine3.24 AS base

RUN set -eux; \
    apk add --no-cache libpq unzip; \
    apk add --no-cache --virtual .build-deps postgresql-dev $PHPIZE_DEPS; \
    docker-php-ext-install -j"$(nproc)" pdo_pgsql opcache; \
    apk del .build-deps

COPY --from=composer:2.10.3 /usr/bin/composer /usr/bin/composer

COPY social-network-docker/php/php.ini      /usr/local/etc/php/conf.d/zz-app.ini
COPY social-network-docker/php/opcache.ini  /usr/local/etc/php/conf.d/zz-opcache.ini
COPY social-network-docker/php/php-fpm.conf /usr/local/etc/php-fpm.d/zz-pool.conf

# PHP_FPM_MAX_CHILDREN: один argon2id (m=65536) держит ~64 МБ, пик памяти ≈ 22 МБ + 65 МБ × воркеров.
# При лимите контейнера 512 МБ: 4 воркера ≈ 280 МБ, 6 ≈ 413 МБ, 8 на грани OOM, 16 падает с OOM уже при ~24 параллельных логинах.
ENV COMPOSER_ALLOW_SUPERUSER=1 \
    PHP_FPM_MAX_CHILDREN=4 \
    APP_VAR_DIR=/var/www/symfony-var

# Кэш и логи Symfony лежат вне проекта и принадлежат www-data (Kernel читает APP_VAR_DIR).
RUN mkdir -p "$APP_VAR_DIR" && chown www-data:www-data "$APP_VAR_DIR"

WORKDIR /app
EXPOSE 9000


FROM base AS dev

# Xdebug ставится только в dev. Версия берётся последняя стабильная из PECL.
RUN set -eux; \
    apk add --no-cache --virtual .xdebug-build-deps $PHPIZE_DEPS linux-headers; \
    pecl install xdebug; \
    docker-php-ext-enable xdebug; \
    apk del .xdebug-build-deps; \
    rm -rf /tmp/pear

COPY social-network-docker/php/xdebug.ini        /usr/local/etc/php/conf.d/zzz-xdebug.ini
COPY social-network-docker/php/php-fpm.dev.conf  /usr/local/etc/php-fpm.d/zzz-dev.conf

# В разработке opcache перечитывает файлы с диска.
RUN echo "opcache.validate_timestamps=1" > /usr/local/etc/php/conf.d/zzz-dev.ini


FROM base AS bench

WORKDIR /app
COPY packages/core ./packages/core
COPY apps ./apps

RUN set -eux; \
    for app in native symfony; do \
        (cd "apps/${app}" && composer install --no-dev --no-interaction --no-progress --prefer-dist --classmap-authoritative); \
    done; \
    cd apps/symfony; \
    APP_ENV=prod APP_DEBUG=0 APP_SECRET=build-placeholder php bin/console cache:warmup; \
    chown -R www-data:www-data "$APP_VAR_DIR"