#!/bin/sh
set -e

# Ensure required runtime dirs exist (idempotent)
mkdir -p /var/www/html/storage/logs \
         /var/www/html/storage/framework/sessions \
         /var/www/html/storage/framework/views \
         /var/www/html/storage/framework/cache \
         /var/www/html/bootstrap/cache

touch /var/www/html/storage/logs/laravel.log

# FIX: mounted volumes need runtime permissions
if [ "$(id -u)" = "0" ]; then
  chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache || true
  chmod -R ug+rwX /var/www/html/storage /var/www/html/bootstrap/cache || true
fi

exec "$@"
