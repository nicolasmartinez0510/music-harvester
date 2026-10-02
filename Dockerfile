FROM node:22-alpine AS frontend

WORKDIR /build
COPY frontend/package.json frontend/package-lock.json frontend/
RUN cd frontend && npm ci

COPY frontend/ frontend/
RUN cd frontend && npm run build

FROM composer:2 AS vendor

WORKDIR /app

COPY backend/composer.json backend/composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --no-scripts \
    --no-autoloader

COPY backend/ .
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev

FROM php:8.4-fpm-bookworm AS app

# PHP extensions + Python for streamrip (Deezer FLAC downloads in the worker).
# pdo_sqlite is kept for PHPUnit (:memory:); runtime uses pdo_pgsql.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        libpq-dev \
        libsqlite3-dev \
        postgresql-client \
        fonts-dejavu-core \
        python3 \
        python3-pip \
        python3-venv \
        unzip \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pdo_sqlite pcntl \
    && apt-get purge -y --auto-remove libsqlite3-dev \
    && rm -rf /var/lib/apt/lists/* \
    && python3 -m pip install --break-system-packages --no-cache-dir "streamrip>=2.0.0" "mutagen>=1.47" "pillow>=10.4" \
    && rip --version

# Static binaries — avoids heavy apt dependency trees (ffmpeg pulls 100+ packages).
COPY --from=mwader/static-ffmpeg:8.1 /ffmpeg /usr/local/bin/ffmpeg
COPY --from=mwader/static-ffmpeg:8.1 /ffprobe /usr/local/bin/ffprobe
COPY --from=denoland/deno:bin /deno /usr/local/bin/deno

# yt-dlp >= 2025.11.12 is required for --js-runtimes (Deno JS runtime for YouTube).
# Older releases fail with: "no such option: --js-runtimes".
ARG YTDLP_VERSION=2026.07.04
ARG TARGETARCH
RUN set -eux; \
    arch="${TARGETARCH:-$(dpkg --print-architecture)}"; \
    case "${arch}" in \
        amd64|x86_64) ytdlp_bin=yt-dlp_linux ;; \
        arm64|aarch64) ytdlp_bin=yt-dlp_linux_aarch64 ;; \
        arm|armv7l) ytdlp_bin=yt-dlp_linux_armv7l ;; \
        *) echo "unsupported architecture: ${arch}" >&2; exit 1 ;; \
    esac; \
    curl -fsSL "https://github.com/yt-dlp/yt-dlp/releases/download/${YTDLP_VERSION}/${ytdlp_bin}" \
        -o /usr/local/bin/yt-dlp; \
    chmod +x /usr/local/bin/yt-dlp; \
    yt-dlp --version

COPY docker/yt-dlp/yt-dlp.conf /etc/yt-dlp.conf
COPY docker/scripts/apply-audio-metadata.py /usr/local/bin/apply-audio-metadata.py
COPY docker/scripts/render-playlist-cover.py /usr/local/bin/render-playlist-cover.py
RUN chmod +x /usr/local/bin/apply-audio-metadata.py /usr/local/bin/render-playlist-cover.py

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY --from=vendor /app/vendor ./vendor
COPY backend/ .
COPY --from=frontend /build/frontend/dist/frontend/browser/ /var/www/html/frontend/dist/frontend/browser/

COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]

FROM nginx:1.27-alpine AS nginx

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/html/public /var/www/html/public
COPY --from=app /var/www/html/frontend/dist /var/www/html/frontend/dist
