#!/bin/sh
set -e

cd /var/www/html

if [ ! -f vendor/autoload.php ]; then
    echo "vendor/ missing — running composer install..."
    composer install \
        --no-dev \
        --no-interaction \
        --prefer-dist \
        --optimize-autoloader
fi

if [ ! -f database/database.sqlite ]; then
    touch database/database.sqlite
    chown www-data:www-data database/database.sqlite
fi

chown -R www-data:www-data storage bootstrap/cache database vendor 2>/dev/null || true

# Run migrations only from the web/app service (php-fpm) to avoid several
# containers writing to the same SQLite file at once. worker/scheduler wait
# for the app healthcheck before starting.
if [ "$1" = "php-fpm" ]; then
    echo "Running database migrations..."
    php artisan migrate --force
fi

exec "$@"
