#!/bin/sh
set -e

echo "Waiting for postgres..."
until php -r "new PDO('pgsql:host=${DB_HOST};dbname=${DB_DATABASE}', '${DB_USERNAME}', '${DB_PASSWORD}');" 2>/dev/null; do
  sleep 1
done

QUEUE_NAME=${QUEUE_NAME:-default}

echo "Starting queue worker for '${QUEUE_NAME}'..."
exec php artisan queue:work --queue="$QUEUE_NAME" --tries=3 --sleep=3 --max-time=3600
