FROM node:22-alpine AS frontend

WORKDIR /build
COPY frontend/package.json frontend/package-lock.json frontend/
RUN cd frontend && npm ci

COPY frontend/ frontend/
RUN cd frontend && npm run build

FROM php:8.4-fpm-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    unzip \
    curl \
    ca-certificates \
    ffmpeg \
    python3 \
    python3-pip \
    libsqlite3-dev \
    && curl -fsSL https://deno.land/install.sh | DENO_INSTALL=/usr/local sh \
    && ln -sf /usr/local/bin/deno /usr/bin/deno \
    && pip3 install --break-system-packages --no-cache-dir "yt-dlp[default]" \
    && docker-php-ext-install pdo_sqlite pcntl \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY docker/yt-dlp/yt-dlp.conf /etc/yt-dlp.conf

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .
COPY --from=frontend /build/frontend/dist/frontend/browser/ /var/www/html/frontend/dist/frontend/browser/

COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]
