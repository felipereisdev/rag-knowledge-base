#!/bin/sh
set -e

# Ensure a .env exists (production image ships without one). Copy from .env.example
# so config cascades (file then environment variables from docker-compose).
if [ ! -f .env ]; then
    echo "Creating .env from .env.example..."
    cp .env.example .env
fi

# Drop stale package/service discovery caches BEFORE any artisan call. The
# bootstrap/cache volume can outlive an image (and is shared with the --dev
# app-dev image), so a cached manifest may reference packages this --no-dev
# image doesn't have (e.g. laravel/pail) — which crashes every artisan command
# and loops the container. Regenerating from the current image is always safe.
#
# config.php goes with them: a config snapshot from a previous image would pin
# values (e.g. the importance prompt/rules versions surfaced to operators) to
# whatever the OLD code said, while the new code runs. Config is rebuilt from
# .env + the environment on every boot, so dropping it is always safe too.
rm -f bootstrap/cache/packages.php bootstrap/cache/services.php bootstrap/cache/config.php

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