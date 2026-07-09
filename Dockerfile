FROM node:22-alpine AS frontend

WORKDIR /build
COPY frontend/package.json frontend/package-lock.json frontend/
RUN cd frontend && npm ci

COPY frontend/ frontend/
RUN cd frontend && npm run build

FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --no-scripts \
    --no-autoloader

COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev

FROM php:8.4-fpm-bookworm AS app

# Only packages needed to compile PHP extensions (no ffmpeg/python/git).
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        libsqlite3-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_sqlite pcntl \
    && apt-get purge -y --auto-remove libsqlite3-dev \
    && rm -rf /var/lib/apt/lists/*

# Static binaries — avoids heavy apt dependency trees (ffmpeg pulls 100+ packages).
COPY --from=mwader/static-ffmpeg:8.1 /ffmpeg /usr/local/bin/ffmpeg
COPY --from=mwader/static-ffmpeg:8.1 /ffprobe /usr/local/bin/ffprobe
COPY --from=denoland/deno:bin /deno /usr/local/bin/deno

ARG YTDLP_VERSION=2025.06.30
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

WORKDIR /var/www/html

COPY --from=vendor /app/vendor ./vendor
COPY . .
COPY --from=frontend /build/frontend/dist/frontend/browser/ /var/www/html/frontend/dist/frontend/browser/

COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]

FROM nginx:1.27-alpine AS nginx

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/html/public /var/www/html/public
COPY --from=app /var/www/html/frontend/dist /var/www/html/frontend/dist
