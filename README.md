# Music Harvester

Laravel API + queue worker for downloading music from YouTube Music and Deezer to a local library (Synology NAS).

## Stack

- **Backend:** Laravel 12 (PHP 8.4) + PostgreSQL + database queue
- **Worker:** `yt-dlp` + `ffmpeg` + Deno (YouTube Music) and `streamrip` (Deezer FLAC)
- **Frontend:** Angular 19 SPA (same origin via nginx)
- **Proxy:** nginx (Angular UI + `/api` → Laravel)

## Quick start (Docker, local)

```bash
cp .env.example .env
cd backend && composer install && cd ..   # only needed for local dev bind-mount

# Write APP_KEY into the root .env (compose + env_file read that file):
docker compose -f docker-compose.yml -f docker-compose.dev.yml run --rm app php artisan key:generate --show
# paste the output into APP_KEY=... in .env, then:
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

See [docs/providers.md](docs/providers.md) for ARL setup, streamrip choice, **Explorar / catálogo**, and how to add a new provider.

## Explorar (buscar y destacar)

UI **Explorar** (`/browse`): búsqueda en providers con `has_catalog` (Deezer). Desde resultados o fichas podés **Descargar ahora** (`POST /api/downloads`) o **Destacar** una playlist (`POST /api/playlists` + sync). El catálogo es API pública; el FLAC autenticado sigue necesitando ARL HiFi.

## Saved playlists (re-sync)

Guardá una URL de playlist (YouTube Music o cualquier provider del registry) y sincronizala manual o periódicamente. Solo se descargan tracks **nuevos**.

Archivos de playlists sincronizadas:

```text
{MUSIC_PATH}/playlists/{id}-{slug}/
  01 - artist - title.mp3
  …
  {id}-{slug}.m3u
```

El `.m3u` (extended, rutas relativas) se regenera en cada sync y tras cada track descargado — útil para Navidrome u otros gestores. Descargas one-shot (track/álbum) siguen en `{artista}/{album}/`.

| Acción | API / UI |
|--------|----------|
| Guardar | `POST /api/playlists` · UI **Playlists** |
| Listar / detalle | `GET /api/playlists`, `GET /api/playlists/{id}` |
| Sync manual | `POST /api/playlists/{id}/sync` |
| Sync automático | scheduler cada 5 min → `php artisan playlists:sync` |

El sync usa **solo** `MusicProviderRegistry::resolveForUrl` (no importa providers concretos). Tracks que desaparecen de la playlist remota se marcan `skipped` (no se borran archivos).

Deezer y YouTube Music **no** ofrecen webhooks/WebSocket públicos para cambios remotos. La detección de tracks nuevos es por **polling** (intervalo por playlist, default 5 min).

```bash
# Forzar sync de playlists due
docker compose exec app php artisan playlists:sync
```

Intervalo por playlist: `sync_interval_minutes` (default 5). Toggle `sync_enabled` en el detalle.

## Synology NAS

Full deploy guide (Container Manager, `/volume1/music`, YouTube Music cookies, Media Indexing, Audio Station):

**[docs/synology.md](docs/synology.md)**

Quick start on the NAS:

```bash
cp .env.example .env
# place cookies at cookies/cookies.txt
docker compose -f docker-compose.yml -f docker-compose.synology.yml up -d --build
```

For local dev (bind-mounts source code; requires `cd backend && composer install` on the host):

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build
```

## Layout

```
backend/                   # Laravel API + queue worker
  app/Domain/Music/        # entities, value objects, contracts
  app/Application/         # use cases (handlers)
  app/Infrastructure/      # yt-dlp, providers, persistence
frontend/                  # Angular 19 SPA
docker/                    # nginx, php entrypoint, yt-dlp conf
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
