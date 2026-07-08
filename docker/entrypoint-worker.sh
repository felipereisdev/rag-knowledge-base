#!/bin/sh
set -e

echo "Waiting for postgres..."
until php -r "new PDO('pgsql:host=${DB_HOST};dbname=${DB_DATABASE}', '${DB_USERNAME}', '${DB_PASSWORD}');" 2>/dev/null; do
  sleep 1
done

echo "Running migrations..."
php artisan migrate --force --no-interaction

echo "Starting queue worker..."
exec php artisan queue:work --tries=3 --sleep=3 --max-time=3600