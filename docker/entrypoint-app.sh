#!/bin/sh
set -e

# Ensure a .env exists (production image ships without one). Copy from .env.example
# so config cascades (file then environment variables from docker-compose).
if [ ! -f .env ]; then
    echo "Creating .env from .env.example..."
    cp .env.example .env
fi

# Generate APP_KEY on first boot if missing (plug-and-play: no manual setup needed)
if [ -z "$APP_KEY" ] && ! grep -q "^APP_KEY=base64:" .env 2>/dev/null; then
    echo "Generating APP_KEY..."
    php artisan key:generate --force --no-interaction
fi

echo "Running migrations..."
php artisan migrate --force --no-interaction

echo "Seeding database..."
php artisan db:seed --force --no-interaction

# Clear Martis caches on every boot. The schema/dashboards layers have a
# "forever" TTL and live in the cache store (DB), so they persist across image
# rebuilds — a stale cached schema silently masks Resource/field/action changes
# until cleared. Clearing here makes a rebuild always reflect the current code.
echo "Clearing Martis caches..."
php artisan martis:cache:clear --no-interaction || true

echo "Starting PHP-FPM..."
exec php-fpm