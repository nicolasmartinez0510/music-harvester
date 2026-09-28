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

# php-fpm workers and the queue worker run as www-data; they must be able to
# write logs, cache and sessions.
if ! chown -R www-data:www-data storage bootstrap/cache; then
    echo "WARNING: chown failed — bind-mounted host folder? Falling back to chmod."
    chmod -R a+rw storage bootstrap/cache || true
fi

wait_for_db() {
    if [ "${DB_CONNECTION:-pgsql}" != "pgsql" ]; then
        return 0
    fi

    host="${DB_HOST:-db}"
    port="${DB_PORT:-5432}"
    user="${DB_USERNAME:-music}"
    database="${DB_DATABASE:-music_harvester}"

    echo "Waiting for PostgreSQL at ${host}:${port}..."
    i=0
    while [ "$i" -lt 60 ]; do
        if pg_isready -h "$host" -p "$port" -U "$user" -d "$database" >/dev/null 2>&1; then
            echo "PostgreSQL is ready."
            return 0
        fi
        i=$((i + 1))
        sleep 1
    done

    echo "ERROR: PostgreSQL did not become ready in time." >&2
    return 1
}

# Run migrations only from the web/app service (php-fpm) to avoid several
# containers migrating at once. worker/scheduler wait for the app healthcheck.
if [ "$1" = "php-fpm" ]; then
    wait_for_db
    echo "Running database migrations..."
    php artisan migrate --force
    php artisan db:seed --class=AdminUserSeeder --force
    mkdir -p storage/app/private/cookies storage/app/private/tmp-downloads
fi

exec "$@"
