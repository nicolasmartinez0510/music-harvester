# Music Harvester — contexto de sesión

Notas vivas del proyecto. Se actualizan al cerrar cada feature (ver rule `feature-completion-workflow`).

## Estado del producto

Fundación NAS + v2 providers/playlists/content manager **listas**. App multi-usuario con Sanctum, Explorar, Mi Colección (Deezer), Descargas, Playlists sync→disco/M3U, Settings y Usuarios (admin).

Plan fundacional: [`.cursor/plans/music_harvester_nas.plan.md`](plans/music_harvester_nas.plan.md) — scaffold / youtube-provider / api / angular-ui / synology-docs ✅

## Stack y entorno local

- **Backend:** Laravel 12 + PHP 8.4, **PostgreSQL 16**, queue en DB (`QUEUE_CONNECTION=database`)
- **Frontend:** Angular 19 SPA (same-origin vía nginx; hot reload en `:4200` con proxy). PWA instalable en build de producción (`@angular/service-worker`): manifest + cache del shell. No cachea `/api` ni `/sanctum`. La instalación pide HTTPS (reverse proxy del NAS); en `:8085` HTTP el browser puede no ofrecer “instalar”. `ng serve` no registra el service worker.
- **Docker Compose:** `db`, `app`, `worker`, `scheduler`, `nginx`. El worker de cola arranca como `www-data` (el mismo usuario que php-fpm) para que borrar un job pueda `unlink` en `/music`. El entrypoint, como root, hace `chmod o+rwx` de los directorios de `MUSIC_PATH` (carpetas viejas quedaron `root:root` 775).
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

Sidebar Trinomio (claro/oscuro), colapsable a rail en desktop y drawer ≤840px. En móvil (≤640px) las tablas de Descargas, Playlists y detalle de playlist pasan a filas apiladas, con las acciones arriba a la derecha. En Explorar y Mi Colección, descargar, estrella y sync son iconos a la derecha de la fila; el preview de un track es un play sobre la portada. La discografía pasa a 6 ítems por página. Safe-area en barra móvil y drawer. Toasts centrados abajo.

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
- Deezer catalog: API pública `api.deezer.com`; hits de álbum incluyen `record_type` (`album` | `ep` | `single`); hits de track incluyen `preview_url` (snip ~30s de Deezer, o null). El tracklist de un álbum no trae `cover_*`: la miniatura sale de `md5_image` (`e-cdns-images.dzcdn.net`, 250×250)
- Portada de artista: `picture_xl` (fallback `picture_big` / `picture_medium`) en `cover_url`
- Bio de artista: Wikipedia (es → en). User-Agent con URL del repo; un 403/429 no se cachea como “sin bio”
- Deezer download nativo: streamrip + ARL → FLAC / MP3 320
- Deezer híbrido: match YouTube → yt-dlp (no lossless)
- Metadata post-descarga Deezer: enricher + mutagen (tags, cover, lyrics) — plan `deezer_audio_metadata` ✅
- Mi Colección: `UserLibrarySource` + `GET /api/library/{provider}/{artists|albums|tracks|playlists}` (Deezer); YTM cookies **no** listan library
- Docs: [docs/providers.md](../docs/providers.md)

## Playlists (descarga local)

- `saved_playlists` + sync job/scheduler; intervalo en **minutos** (default 5)
- Disco: `playlists/{id}-{slug}/` + M3U regenerado en cada sync (paths relativos a la carpeta de la playlist, así un tema reutilizado apunta a `{artist}/{album}/` sin copiar audio)
- Dedup: tabla `downloaded_tracks` (`user_id` + `provider` + `external_id` → `file_path`). Sync de playlist y descargas de álbum/track omiten lo ya indexado si el archivo sigue en disco. Borrar un job no borra un archivo cuyo índice pertenece a otro job. Backfill de descargas viejas: `php artisan downloads:backfill-index` (en el NAS, dentro de `app`; `--dry-run` primero). Resuelve cada job `done`, matchea el archivo por nombre y registra el id del tema. Álbumes primero, así la playlist reutiliza ese path
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
- Playlist folders + M3U + sync 5 min + dedup por índice de tracks (`downloaded_tracks`)
- Auth/admin/tenancy + favoritos API
- Mi Colección (Deezer)
- Deezer audio metadata (mutagen)
- Browse UX polish, album track spinners, album back navigation
- Discografía del artista en tabs **Álbumes discográficos** / **Sencillos y EPs** (`record_type` de Deezer en `CatalogHit`). Página de 12 por tab en desktop y 6 en móvil; el botón Descargar se alinea al fondo de la fila
- Fondo de la ficha de artista: portada difuminada (blur 20px) solo en el área de contenido; el sidebar queda sólido. Se limpia al salir de la ruta
- Bio de Wikipedia vuelve a mostrarse: el User-Agent anterior recibía 403/429 y el fallo quedaba cacheado 24 h
- Preview snip ~30s en tracks Deezer (Explorar + Mi Colección): play sobre la portada (hover en desktop; en móvil siempre visible, con la portada atenuada). Spinner mientras carga y anillo de progreso de la duración del clip. Un solo `Audio`, sin barra. Plan: [track_preview_snip](plans/track_preview_snip_abbb972b.plan.md)
- Portadas de descarga Deezer: el enricher reintenta `cover_xl`, valida JPEG/PNG, cachea bytes por álbum y loguea el fallo. Mutagen ya no borra la carátula embebida si el fetch no trae reemplazo (streamrip o thumb de hybrid se conservan). Lo ya descargado se corrige con `php artisan downloads:repair-covers` (solo archivos indexados sin portada; `--dry-run` primero). Plan: [fix_download_covers](plans/fix_download_covers_f3288b6b.plan.md)
- Miniaturas de tracks en la ficha de álbum: Deezer manda `md5_image` en vez de `cover_medium`; `cover_url` se arma con esa hash para que el listado no quede en el placeholder
- UI responsive (tablas y hits en ≤640px, safe-area, acciones como iconos) + PWA instalable (shell cacheado; sin offline de catálogo ni descargas). Nueva versión avisa con toast “Recargar”. Plan: [responsive_pwa](plans/responsive_pwa_38f12cec.plan.md)

## Próximos / pendientes

1. **Épica Puentes de playlists** (Soundiiz-like, Deezer ↔ YTM; sin descarga) — [playlist_bridge_epic](plans/playlist_bridge_epic_ec620289.plan.md)
2. Spotify / Tidal / Apple: después, y en puentes primero solo metadata

## Decisiones / convenciones

- DDD: `app/Domain`, `app/Application`, `app/Infrastructure`
- Providers pluggables; Spotify queda para después del eje actual
- Biblioteca real en filesystem; **Postgres** para jobs, playlists, auth, settings
- Synology: `docker-compose.yml` + `docker-compose.synology.yml` (nunca `dev.yml` en el NAS) — [docs/synology.md](../docs/synology.md)
- Al cerrar feature: actualizar este archivo, incluir plan en commit si existe, rebuild contenedores dev
