# Kalorie: PHP-FPM with the extensions the app needs (GD and EXIF for photos, intl; SQLite and OPcache are built in).
FROM composer:2.10.2 AS composer

FROM php:8.5.12-fpm-alpine3.24 AS base
RUN apk add --no-cache icu-libs libjpeg-turbo libpng libwebp freetype \
 && apk add --no-cache --virtual .build icu-dev libjpeg-turbo-dev libpng-dev libwebp-dev freetype-dev \
 && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
 && docker-php-ext-install -j"$(nproc)" gd intl exif \
 && apk del .build
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-kalorie.ini
COPY docker/fpm.conf /usr/local/etc/php-fpm.d/zz-kalorie.conf
WORKDIR /app

FROM base AS dev
COPY --from=composer /usr/bin/composer /usr/bin/composer

FROM dev AS build
# Composer plugins (symfony/runtime writes autoload_runtime.php) run only with this as root.
ENV COMPOSER_ALLOW_SUPERUSER=1
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress
COPY . .
RUN composer dump-autoload --no-dev --classmap-authoritative \
 && APP_ENV=prod APP_DEBUG=0 php bin/console cache:warmup

FROM base AS prod
# The app runs as Konrad's uid (1000): it owns the data volume and can use the AI
# bridge's socket, which the bridge creates as 0600.
COPY --from=build --chown=1000:1000 /app /app
COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/kalorie-entrypoint
ENV APP_ENV=prod APP_DEBUG=0
USER 1000:1000
ENTRYPOINT ["kalorie-entrypoint"]
CMD ["php-fpm"]
