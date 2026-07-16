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

# Named volumes start empty — make sure Laravel's runtime dirs exist.
mkdir -p \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

if [ ! -f database/database.sqlite ]; then
    touch database/database.sqlite
fi

# php-fpm workers and the queue worker run as www-data; they must be able to
# write logs, cache, sessions and the SQLite database (and its directory, so
# SQLite can create -wal/-journal lock files).
if ! chown -R www-data:www-data storage bootstrap/cache database; then
    echo "WARNING: chown failed — bind-mounted host folder? Falling back to chmod."
    chmod -R a+rw storage bootstrap/cache database || true
fi

# Run migrations only from the web/app service (php-fpm) to avoid several
# containers writing to the same SQLite file at once. worker/scheduler wait
# for the app healthcheck before starting.
if [ "$1" = "php-fpm" ]; then
    echo "Running database migrations..."
    php artisan migrate --force
    chown -R www-data:www-data database 2>/dev/null || true
fi

exec "$@"
