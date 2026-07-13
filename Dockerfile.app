# Stage 0: Martis consumer extensions
FROM node:22-alpine AS extensions-builder

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY vite.extensions.config.ts ./
COPY resources/js/martis-extensions ./resources/js/martis-extensions
RUN npm run build:extensions

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
RUN mkdir -p bootstrap/cache \
    && composer dump-autoload --no-dev --optimize --no-scripts \
    && php artisan package:discover --ansi

# Stage 2: Final (production)
FROM php:8.3-fpm-alpine AS production

RUN apk add --no-cache \
    postgresql-libs \
    libzip \
    libpq-dev \
    libzip-dev \
    icu-libs \
    nginx \
    && docker-php-ext-install pdo_pgsql pgsql bcmath opcache zip \
    && apk del libpq-dev libzip-dev

COPY docker/php/php.ini /usr/local/etc/php/conf.d/production.ini

WORKDIR /var/www/html

COPY --from=builder /var/www/html .
COPY --from=extensions-builder /app/public/vendor/martis-user ./public/vendor/martis-user
COPY docker/entrypoint-app.sh /usr/local/bin/entrypoint-app.sh
COPY docker/entrypoint-worker.sh /usr/local/bin/entrypoint-worker.sh
RUN chmod +x /usr/local/bin/entrypoint-app.sh /usr/local/bin/entrypoint-worker.sh

# Publish Martis SPA assets into the image so nginx can serve them
RUN php artisan martis:publish-assets --no-interaction

RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

ENTRYPOINT ["/usr/local/bin/entrypoint-app.sh"]

# Stage 2-dev-builder: Reinstall with dev dependencies for testing
FROM builder AS dev-builder

RUN composer install --dev --optimize-autoloader --no-interaction --no-scripts
RUN composer dump-autoload --optimize

# Stage app-dev: Final image with dev dependencies (Pest, PHPStan, Pint)
FROM php:8.3-fpm-alpine AS app-dev

RUN apk add --no-cache \
    postgresql-libs \
    libzip \
    libpq-dev \
    libzip-dev \
    icu-libs \
    && docker-php-ext-install pdo_pgsql pgsql bcmath opcache zip \
    && apk del libpq-dev libzip-dev

COPY docker/php/php.ini /usr/local/etc/php/conf.d/production.ini

WORKDIR /var/www/html

COPY --from=dev-builder /var/www/html .
COPY docker/entrypoint-app.sh /usr/local/bin/entrypoint-app.sh
COPY docker/entrypoint-worker.sh /usr/local/bin/entrypoint-worker.sh
RUN chmod +x /usr/local/bin/entrypoint-app.sh /usr/local/bin/entrypoint-worker.sh

RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

ENTRYPOINT ["/usr/local/bin/entrypoint-app.sh"]
