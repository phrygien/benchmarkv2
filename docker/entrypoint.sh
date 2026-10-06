#!/bin/sh
set -e
cd /var/www/html

mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs
chown -R www-data:www-data storage bootstrap/cache

php artisan storage:link --force || true

if [ "$RUN_MIGRATIONS" = "true" ]; then
  php artisan migrate --force
fi

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

exec "$@"
