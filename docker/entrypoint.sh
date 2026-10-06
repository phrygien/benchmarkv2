#!/bin/sh
set -e
cd /var/www/html

# Droits sur le volume storage
mkdir -p storage/framework/{cache,sessions,views} storage/logs
chown -R www-data:www-data storage bootstrap/cache

# Lien public/storage
php artisan storage:link --force || true

# Migrations (optionnel)
if [ "$RUN_MIGRATIONS" = "true" ]; then
  php artisan migrate --force
fi

# Caches de production
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

exec "$@"
