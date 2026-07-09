#!/bin/sh
set -e

cd /var/www/html

if [ ! -f database/database.sqlite ]; then
    touch database/database.sqlite
    chown www-data:www-data database/database.sqlite
fi

chown -R www-data:www-data storage bootstrap/cache database 2>/dev/null || true

exec "$@"
