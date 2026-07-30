FROM php:8.5-fpm-alpine AS base

# intl needs the ICU headers to compile, so icu-dev is installed as a virtual
# build dependency and removed again; icu-libs is the runtime half that stays.
# pdo_sqlite and opcache are bundled and enabled in php:8.5-fpm-alpine, so
# only intl is built here. Without icu-dev this stage fails outright
# ("Package 'icu-uc' not found"), which is why the image never built.
RUN apk add --no-cache \
    sqlite-libs \
    icu-libs \
    && apk add --no-cache --virtual .build-deps \
    $PHPIZE_DEPS \
    icu-dev \
    && docker-php-ext-install -j"$(nproc)" intl \
    && apk del .build-deps

# The base image activates NEITHER shipped php.ini, so PHP's own defaults
# apply: display_errors=On and log_errors=Off. Every warning, notice,
# deprecation and fatal would be written into the HTTP response body
# (disclosing paths, file/line and interpolated values) and nowhere else.
# APP_DEBUG only governs thrown exceptions, so it cannot cover this.
# error_log goes to worker stderr, which php-fpm forwards to the container
# log via catch_workers_output.
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/php/error-reporting.ini "$PHP_INI_DIR/conf.d/zz-error-reporting.ini"

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

FROM base AS deps

# composer.lock* — the lock exists in created projects (composer create-project
# writes it) but not in the skeleton repo itself; the glob keeps both buildable.
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-scripts --prefer-dist --optimize-autoloader

FROM base AS production

COPY --from=deps /app/vendor /app/vendor
COPY . /app

RUN composer dump-autoload --optimize --no-dev \
    && mkdir -p /app/storage \
    && chown -R www-data:www-data /app/storage

ENV APP_ENV=production
ENV APP_DEBUG=false
ENV WAASEYAA_DB=/app/storage/waaseyaa.sqlite

EXPOSE 9000

USER www-data
