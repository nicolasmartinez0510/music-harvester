# Music Harvester

Laravel API + queue worker for downloading music from YouTube Music and Deezer to a local library (Synology NAS).

## Stack

- **Backend:** Laravel 12 (PHP 8.4) + SQLite + database queue
- **Worker:** `yt-dlp` + `ffmpeg` + Deno (YouTube Music) and `streamrip` (Deezer FLAC)
- **Frontend:** Angular 19 SPA (same origin via nginx)
- **Proxy:** nginx (Angular UI + `/api` → Laravel)

## Quick start (Docker, local)

```bash
cp .env.example .env
composer install   # only needed for local dev bind-mount

docker compose -f docker-compose.yml -f docker-compose.dev.yml run --rm app php artisan key:generate
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build
```

Open http://localhost:8085 — Angular UI at `/`, API at `/api`, health at `/up`.

> **Synology / servidor:** no uses `docker-compose.dev.yml`. Ver [docs/synology.md](docs/synology.md).

## Frontend (Angular)

Requires **Node.js ≥ 18.19** (Angular 19). If you use [nvm](https://github.com/nvm-sh/nvm):

```bash
cd frontend
nvm use          # reads .nvmrc → Node 22
node -v          # should show v18+ (not v10)
npm install
npm run build
```

If `nvm use` says the version is missing: `nvm install 22`.

Build the SPA (output in `frontend/dist/`):

```bash
cd frontend
nvm use && npm install && npm run build
```

Local dev with hot reload and API proxy to Docker:

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d app worker scheduler nginx
cd frontend && nvm use && npm start
# UI: http://localhost:4200  (proxies /api → :8085)
```

Production images build the frontend automatically in the Docker multi-stage `Dockerfile`.

## Services

| Service   | Role                                      |
|-----------|-------------------------------------------|
| `app`     | PHP-FPM + Laravel                         |
| `worker`  | `php artisan queue:work`                    |
| `scheduler` | `php artisan schedule:work`             |
| `nginx`   | Reverse proxy, port `8085`                |

## Volumes

- `music_data` → `/music` inside containers (map to `/volume1/music` on Synology)
- `./cookies` → `/cookies` read-only
  - YouTube Music: `cookies/youtube/cookies.txt` (or legacy `cookies/cookies.txt`)
  - Deezer ARL: set in **Settings → Deezer** (or `DEEZER_ARL` env) — never commit it

## Providers

| Provider | Resolve | Download | Quality |
|----------|---------|----------|---------|
| YouTube Music | yt-dlp metadata | yt-dlp | MP3 320 / M4A |
| Deezer (native) | `api.deezer.com` | streamrip + ARL | **FLAC** (HiFi) or MP3 320 |
| Deezer (hybrid) | `api.deezer.com` | match → YouTube → yt-dlp | YouTube quality (not lossless) |

`GET /api/providers` lists enabled providers, credentials status, and supported qualities.

See [docs/providers.md](docs/providers.md) for ARL setup, streamrip choice, and how to add a new provider.

## Synology NAS

Full deploy guide (Container Manager, `/volume1/music`, YouTube Music cookies, Media Indexing, Audio Station):

**[docs/synology.md](docs/synology.md)**

Quick start on the NAS:

```bash
cp .env.example .env
# place cookies at cookies/cookies.txt
docker compose -f docker-compose.yml -f docker-compose.synology.yml up -d --build
```

For local dev (bind-mounts source code; requires `composer install` on the host):

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build
```

## DDD layout

```
app/Domain/Music/          # entities, value objects, contracts
app/Application/           # use cases (handlers)
app/Infrastructure/        # yt-dlp, providers, persistence
```

## Verify worker tools

```bash
docker compose exec worker yt-dlp --version
docker compose exec worker deno --version
docker compose exec worker ffmpeg -version
docker compose exec worker rip --version
```

After changing `Dockerfile` or `docker/yt-dlp/yt-dlp.conf`, rebuild the image:

```bash
docker compose build app
docker compose up -d --force-recreate app worker scheduler
```

The image uses static `ffmpeg`, the `yt-dlp` release binary, Deno, and `streamrip` (Python) for Deezer FLAC.

**Build fails with `docker-credential-desktop` not found?** Your Docker config points to a missing credential helper. Either open Docker Desktop, or build with a minimal config:

```bash
mkdir -p /tmp/docker-nocreds && printf '{"auths":{}}\n' > /tmp/docker-nocreds/config.json
DOCKER_CONFIG=/tmp/docker-nocreds docker compose build app
```
