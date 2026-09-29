# Music Harvester — contexto de sesión

Notas vivas del proyecto. Se actualizan al cerrar cada feature (ver rule `feature-completion-workflow`).

## Estado del producto

Fundación NAS + v2 providers/playlists/content manager **listas**. App multi-usuario con Sanctum, Explorar, Mi Colección (Deezer), Descargas, Playlists sync→disco/M3U, Settings y Usuarios (admin).

Plan fundacional: [`.cursor/plans/music_harvester_nas.plan.md`](plans/music_harvester_nas.plan.md) — scaffold / youtube-provider / api / angular-ui / synology-docs ✅

## Stack y entorno local

- **Backend:** Laravel 12 + PHP 8.4, **PostgreSQL 16**, queue en DB (`QUEUE_CONNECTION=database`)
- **Frontend:** Angular 19 SPA (same-origin vía nginx; hot reload en `:4200` con proxy)
- **Docker Compose:** `db`, `app`, `worker`, `scheduler`, `nginx`
- **Dev:** `docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build`
- **Puerto local:** **8085** → UI `/`, API `/api`, health `/up`
- **yt-dlp:** binario pineado en Dockerfile (`YTDLP_VERSION=2026.07.04`) + Deno 2.x + `/etc/yt-dlp.conf` (`player_client=web_safari,web,mweb,android`; sin `--js-runtimes`)
- **Deezer FLAC:** streamrip (`rip`) + ARL; post-tags con mutagen
- **Layout:** `backend/` + `frontend/`; compose/Docker/docs en la raíz
- **Dev local música:** `backend/storage/music/` → `/music` (override/dev compose)
- **Tests PHP:** SQLite in-memory (`phpunit.xml`); runtime Docker usa `pgsql`

## Superficie UI / rutas

| Ruta | Qué es |
|------|--------|
| `/downloads` | Cola + historial; nueva descarga en modal (raíz redirige acá) |
| `/browse`… | Explorar: búsqueda, artista, álbum, playlist (Deezer catalog) |
| `/collection/:provider/:kind` | Mi Colección (hoy Deezer vía ARL + gw) |
| `/playlists`… | Playlists guardadas + sync |
| `/settings` | Destino/credenciales (user) + globales (admin) |
| `/users` | Admin: usuarios + approve server storage |
| Auth | `/login`, `/register`, `/verify-email`, `/forgot-password`, `/reset-password` |

Sidebar Trinomio (claro/oscuro), colapsable a rail, Favoritos de artistas + Mi Colección.

## Auth y tenancy

Plan: [`.cursor/plans/user_auth_admin_1389c0c0.plan.md`](plans/user_auth_admin_1389c0c0.plan.md) ✅

- Sanctum SPA; API autenticada salvo health + auth públicos
- Admin seeder (`ADMIN_EMAIL` / `ADMIN_PASSWORD` / `ADMIN_USERNAME`): escribe en `/music` **sin** subcarpeta
- Users: biblioteca `{MUSIC_PATH}/{username}/` solo con `server_storage=approved`; default destino `direct` (job en server → artifact API → limpia temp)
- Credenciales **por usuario**: ARL Deezer en DB; cookies YTM en storage privado por username
- Settings globales (path root, concurrency, enabled_providers): solo admin
- Favoritos de artistas en API (no solo localStorage)

## Providers y catálogo

- Registry: `youtube_music` + `deezer` (`GET /api/providers`: configured, qualities, `has_catalog`, `has_library`)
- Deezer catalog: API pública `api.deezer.com`; hits de álbum incluyen `record_type` (`album` | `ep` | `single`)
- Portada de artista: `picture_xl` (fallback `picture_big` / `picture_medium`) en `cover_url`
- Bio de artista: Wikipedia (es → en). User-Agent con URL del repo; un 403/429 no se cachea como “sin bio”
- Deezer download nativo: streamrip + ARL → FLAC / MP3 320
- Deezer híbrido: match YouTube → yt-dlp (no lossless)
- Metadata post-descarga Deezer: enricher + mutagen (tags, cover, lyrics) — plan `deezer_audio_metadata` ✅
- Mi Colección: `UserLibrarySource` + `GET /api/library/{provider}/{artists|albums|tracks|playlists}` (Deezer); YTM cookies **no** listan library
- Docs: [docs/providers.md](../docs/providers.md)

## Playlists (descarga local)

- `saved_playlists` + sync job/scheduler; intervalo en **minutos** (default 5)
- Disco: `playlists/{id}-{slug}/` + M3U regenerado en cada sync
- One-shot downloads siguen en artista/álbum
- Distinto del eje futuro **Puentes** (copiar playlist entre providers sin bajar audio)

## Cookies / yt-dlp (operativo)

- Compose monta `./cookies:/cookies:ro` en `app` / `worker` / `scheduler`
- Paths típicos: `cookies/youtube/cookies.txt` o legacy `cookies/cookies.txt`; por usuario autenticado van a storage privado
- `YtDlpDownloader` copia a `/tmp` si el mount es RO (yt-dlp reescribe al salir)
- Cookies caducan; re-exportar con sesión en music.youtube.com (“Get cookies.txt LOCALLY”); **no commitear**
- Tras cambiar Dockerfile/imagen: rebuild compose; tras PHP en worker: a menudo basta `docker compose restart worker`

## Features recientes (✅)

- Épicas v2: providers, playlists sync, content manager (Explorar)
- UI Trinomio + sidebar fijo/colapsable + playlists polish
- Postgres + historial descargas (metadata, delete con archivos, sync “Descargado” en Browse)
- Playlist folders + M3U + sync 5 min
- Auth/admin/tenancy + favoritos API
- Mi Colección (Deezer)
- Deezer audio metadata (mutagen)
- Browse UX polish, album track spinners, album back navigation
- Discografía del artista en tabs **Álbumes discográficos** / **Sencillos y EPs** (`record_type` de Deezer en `CatalogHit`). Página de 12 por tab; el botón Descargar se alinea al fondo de la fila
- Fondo de la ficha de artista: portada difuminada (blur 20px) solo en el área de contenido; el sidebar queda sólido. Se limpia al salir de la ruta
- Bio de Wikipedia vuelve a mostrarse: el User-Agent anterior recibía 403/429 y el fallo quedaba cacheado 24 h

## Próximos / pendientes

1. **Preview snip ~30s** tracks Deezer — [track_preview_snip](plans/track_preview_snip_abbb972b.plan.md)
2. **Épica Puentes de playlists** (Soundiiz-like, Deezer ↔ YTM; sin descarga) — [playlist_bridge_epic](plans/playlist_bridge_epic_ec620289.plan.md)
3. Spotify / Tidal / Apple: después, y en puentes primero solo metadata

## Decisiones / convenciones

- DDD: `app/Domain`, `app/Application`, `app/Infrastructure`
- Providers pluggables; Spotify queda para después del eje actual
- Biblioteca real en filesystem; **Postgres** para jobs, playlists, auth, settings
- Synology: `docker-compose.yml` + `docker-compose.synology.yml` (nunca `dev.yml` en el NAS) — [docs/synology.md](../docs/synology.md)
- Al cerrar feature: actualizar este archivo, incluir plan en commit si existe, rebuild contenedores dev
