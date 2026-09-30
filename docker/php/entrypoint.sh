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
    chmod -R a+rwx storage bootstrap/cache || true
fi
# www-data boots Laravel and must create bootstrap/cache/packages.php.
# chown can report success and still leave the directory unwritable.
chmod -R a+rwx bootstrap/cache || true

relax_music_permissions() {
    music="${MUSIC_PATH:-/music}"
    if [ ! -d "$music" ]; then
        return 0
    fi

    echo "Relaxing directory permissions under ${music} so www-data can delete downloads..."
    # Only directories. Unlink needs write on the parent, not on the audio file.
    # Skip dirs that are already writable by "other" so repeat starts stay cheap.
    find "$music" -type d ! -perm -o+w -exec chmod o+rwx {} + \
        || echo "WARNING: chmod under ${music} failed. The download manager may not be able to delete files."
    # Old root-owned playlist files cannot be overwritten by www-data.
    find "$music" -type f -name '*.m3u' ! -perm -o+w -exec chmod o+rw {} + \
        || echo "WARNING: chmod of playlist .m3u files under ${music} failed."
}

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
    chown -R www-data:www-data storage/app/private || true
fi

# Directories under the library must be writable by www-data. Downloads used to
# be created by a root queue worker; php-fpm (www-data) then could not unlink them.
if [ "$(id -u)" = "0" ]; then
    relax_music_permissions
fi

# The queue worker creates the files the API later deletes. It has to be the
# same user as php-fpm. php-fpm itself stays root so the master process can start.
if [ "$1" = "php" ] && [ "${2:-}" = "artisan" ] && [ "${3:-}" = "queue:work" ]; then
    if [ "$(id -u)" = "0" ]; then
        if ! command -v setpriv >/dev/null 2>&1; then
            echo "ERROR: setpriv is required so the queue worker can run as www-data." >&2
            exit 1
        fi
        echo "Starting queue worker as www-data..."
        # www-data cannot write /root. Keep tool caches (yt-dlp, deno) in /tmp.
        case "${HOME:-/root}" in
            /root) export HOME=/tmp ;;
        esac
        exec setpriv --reuid=www-data --regid=www-data --init-groups "$@"
    fi
fi

exec "$@"
