# Music Harvester

Laravel API + queue worker for downloading music from YouTube Music to a local library (Synology NAS).

## Stack

- **Backend:** Laravel 12 (PHP 8.4) + SQLite + database queue
- **Worker:** `yt-dlp` + `ffmpeg` (same Docker image as app)
- **Frontend:** Angular 19 SPA (same origin via nginx)
- **Proxy:** nginx (Angular UI + `/api` → Laravel)

## Quick start (Docker)

```bash
cp .env.example .env
# Generate APP_KEY locally or after first build:
docker compose run --rm app php artisan key:generate

docker compose up -d --build
```

Open http://localhost:8085 — Angular UI at `/`, API at `/api`, health at `/up`.

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
docker compose up -d app worker scheduler nginx
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
- `./cookies` → `/cookies` read-only (`cookies/cookies.txt` for YouTube Music)

## Synology NAS

Full deploy guide (Container Manager, `/volume1/music`, YouTube Music cookies, Media Indexing, Audio Station):

**[docs/synology.md](docs/synology.md)**

Quick start on the NAS:

```bash
cp .env.example .env
# place cookies at cookies/cookies.txt
docker compose -f docker-compose.yml -f docker-compose.synology.yml up -d --build
```

For local dev with a host path:

```yaml
# docker-compose.override.yml
services:
  app:
    volumes:
      - ./storage/music:/music
  worker:
    volumes:
      - ./storage/music:/music
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
```

After changing `Dockerfile` or `docker/yt-dlp/yt-dlp.conf`, rebuild the image:

```bash
docker compose build app
docker compose up -d --force-recreate app worker scheduler
```

The image uses static `ffmpeg`, the `yt-dlp` release binary, and Deno copied from official images — no `apt install ffmpeg/python3-pip`, so rebuilds are much faster after the first pull.

**Build fails with `docker-credential-desktop` not found?** Your Docker config points to a missing credential helper. Either open Docker Desktop, or build with a minimal config:

```bash
mkdir -p /tmp/docker-nocreds && printf '{"auths":{}}\n' > /tmp/docker-nocreds/config.json
DOCKER_CONFIG=/tmp/docker-nocreds docker compose build app
```
