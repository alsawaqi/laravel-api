#!/bin/sh
set -e

# Make sure Laravel can write to these folders
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# (optional but nice in dev: create folders if missing)
mkdir -p /var/www/html/storage/framework/views \
         /var/www/html/storage/framework/cache \
         /var/www/html/storage/framework/sessions \
         /var/www/html/storage/logs \
         /var/www/html/bootstrap/cache

touch /var/www/html/storage/logs/laravel.log
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# Now continue normal container startup:
exec "$@"
