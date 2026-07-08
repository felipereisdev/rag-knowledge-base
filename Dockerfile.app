# Stage 1: Builder
FROM php:8.3-fpm-alpine AS builder

RUN apk add --no-cache \
    autoconf \
    build-base \
    libpq-dev \
    libzip-dev \
    linux-headers \
    && docker-php-ext-install pdo_pgsql pgsql bcmath zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

COPY . .
RUN composer dump-autoload --no-dev --optimize --no-scripts

# Stage 2: Final
FROM php:8.3-fpm-alpine

RUN apk add --no-cache \
    libpq-dev \
    libzip-dev \
    icu-libs \
    && docker-php-ext-install pdo_pgsql pgsql bcmath opcache zip \
    && apk del libpq-dev libzip-dev

COPY docker/php/php.ini /usr/local/etc/php/conf.d/production.ini

WORKDIR /var/www/html

COPY --from=builder /var/www/html .
COPY docker/entrypoint-app.sh /usr/local/bin/entrypoint-app.sh
COPY docker/entrypoint-worker.sh /usr/local/bin/entrypoint-worker.sh
RUN chmod +x /usr/local/bin/entrypoint-app.sh /usr/local/bin/entrypoint-worker.sh

RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

ENTRYPOINT ["/usr/local/bin/entrypoint-app.sh"]