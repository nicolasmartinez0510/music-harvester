# Music Harvester — contexto de sesión

Notas vivas del proyecto. Se actualizan a medida que avanzamos.

## Plan de implementación

- Plan completo: [`.cursor/plans/music_harvester_nas.plan.md`](plans/music_harvester_nas.plan.md)
- Progreso: scaffold ✅ | youtube-provider ✅ | api ✅ | angular-ui ⏳ | synology-docs ⏳

## Stack y entorno local

- Laravel 12 + PHP 8.4, SQLite, queue en DB
- Docker Compose: `app`, `worker`, `scheduler`, `nginx`
- Puerto local: **8085** (8080 estaba ocupado)
- API base: `http://localhost:8085/api`
- **yt-dlp:** binario release pineado en Dockerfile (`YTDLP_VERSION`, actualmente 2026.07.04) + Deno 2.x en la imagen + `/etc/yt-dlp.conf`. Deno es el runtime JS por defecto desde yt-dlp 2025.11.12 (no se pasa `--js-runtimes`)

## Problema resuelto: cookies de YouTube

### Síntoma

Jobs fallaban con:

> Sign in to confirm you're not a bot

YouTube bloquea descargas sin sesión autenticada.

### Causa

El worker lee cookies desde `/cookies/cookies.txt` **dentro del contenedor**. Antes Docker usaba un volumen nombrado (`cookies_data`) vacío, **no** la carpeta `./cookies/` del repo.

### Solución aplicada

- `docker-compose.yml` monta `./cookies:/cookies:ro` en `app`, `worker` y `scheduler`
- El usuario exportó `cookies/cookies.txt` (formato Netscape, dominio `.youtube.com`)
- `YtDlpDownloader` copia cookies a `/tmp` si el mount es read-only (yt-dlp intenta reescribir el archivo al salir)
- Tras cambiar el mount: `docker compose up -d --force-recreate app worker scheduler`

### Verificación

```bash
curl http://localhost:8085/api/settings
# cookies_configured debe ser true

docker compose exec worker ls -la /cookies/
docker compose exec worker head -1 /cookies/cookies.txt
```

### Reintentar jobs fallidos

```bash
curl -X POST http://localhost:8085/api/downloads/{id}/retry
```

### Mantenimiento

- Las cookies caducan; re-exportar desde el navegador con sesión en music.youtube.com
- Extensión recomendada: "Get cookies.txt LOCALLY"
- No commitear `cookies/cookies.txt` (tokens de sesión)
- Tras cambios de código PHP, reiniciar worker: `docker compose restart worker`

### Bloqueo resuelto (jul 2026): yt-dlp "Requested format is not available"

**Síntoma:** metadata fetch fallaba aunque las cookies estuvieran OK.

**Causa:** el binario standalone de yt-dlp no incluye runtime JS (Deno) ni scripts EJS para resolver desafíos de YouTube.

**Fix en Dockerfile:**
- binario release de yt-dlp pineado (`ARG YTDLP_VERSION`, >= 2025.11.12 para soporte JS runtime)
- Deno 2.x en la imagen (auto-detectado como runtime JS por defecto)
- `docker/yt-dlp/yt-dlp.conf` → `/etc/yt-dlp.conf` con `player_client=web_safari,web,mweb,android` (sin `--js-runtimes`, rompe binarios viejos)

**Rebuild necesario tras cambiar Dockerfile:**
```bash
docker build -t music-harvester-app .
docker compose up -d --force-recreate app worker scheduler
docker compose restart worker
```

## Próximos pasos (según plan)

1. Confirmar descargas funcionando con cookies
2. **angular-ui** — SPA Angular + nginx same-origin
3. **synology-docs** — deploy en NAS, volumen `/volume1/music`, Media Indexing

## Decisiones / convenciones

- DDD bajo `app/Domain`, `app/Application`, `app/Infrastructure`
- Provider MVP: YouTube Music; Spotify queda para después
- Biblioteca real en filesystem; SQLite solo jobs/config
- **Dev local:** música en `storage/music/` (via `docker-compose.override.yml` → `/music` en contenedores)
