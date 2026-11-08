#!/bin/sh
set -e
cd /var/www/html

[ -f .env ] || cp .env.example .env
[ -d vendor ] || composer install --no-interaction --prefer-dist

if grep -q '^APP_KEY=$' .env; then
  php artisan key:generate --force
fi

mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache
chmod -R 777 storage bootstrap/cache

if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
  until php artisan migrate --force; do
    echo "En attente de MySQL..."
    sleep 3
  done
fi

exec "$@"
