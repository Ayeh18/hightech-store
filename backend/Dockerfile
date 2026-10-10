# syntax=docker/dockerfile:1
#
# Production image for the Laravel API. ONE container runs everything a small free host allows:
#   nginx (web server) + php-fpm (runs the PHP) + the queue worker + the scheduler, kept alive by supervisord.
# Build from this folder:  docker build -t hightech-api .
# Hosts such as Render build it for you: set "Root Directory" to "backend".

# ---- Stage 1: the PHP packages the app needs (no testing or development tools) ----
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader --ignore-platform-reqs

# ---- Stage 2: the running app ----
FROM php:8.5-fpm

COPY --from=ghcr.io/mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/
RUN apt-get update \
    && apt-get install -y --no-install-recommends nginx supervisor \
    && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions pdo_pgsql pgsql bcmath intl gd zip pcntl opcache
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY --from=vendor /app/vendor ./vendor
COPY . .

RUN composer dump-autoload --no-dev --optimize --classmap-authoritative --no-interaction \
    && php artisan package:discover --ansi \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

COPY deploy/nginx.conf.template /etc/nginx/nginx.conf.template
COPY deploy/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY deploy/php-fpm.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY deploy/supervisord.conf /etc/supervisord.conf
COPY deploy/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# The host tells the container which port to listen on through the PORT variable (default 8080).
EXPOSE 8080
ENTRYPOINT ["/entrypoint.sh"]
