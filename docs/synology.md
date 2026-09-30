# Deploy en Synology NAS

Guía para correr Music Harvester en un Synology con **Container Manager** (DSM 7+), guardar descargas en la biblioteca compartida y que **Audio Station** / **DS Audio** las indexe automáticamente.

## Requisitos

| Requisito | Detalle |
|-----------|---------|
| DSM | 7.0 o superior con **Container Manager** instalado |
| CPU / RAM | Cualquier modelo x86 o ARM con Docker; usar `MUSIC_MAX_CONCURRENCY=1` por defecto |
| Red | Puerto libre en el NAS (ej. `8085`) para la UI |
| Cuenta DSM | Usuario con permiso de escritura en la carpeta de música y en la carpeta del proyecto |

## Resumen de rutas

| Ubicación | Ruta en DSM | Ruta en contenedor | Uso |
|-----------|-------------|-------------------|-----|
| Biblioteca | `/volumeN/music` (cualquier volumen DSM) | `/music` | Archivos descargados (Audio Station) |
| Cookies YTM | `…/music-harvester/cookies/youtube/cookies.txt` | `/cookies/youtube/cookies.txt` | Sesión de YouTube Music |
| ARL Deezer | Settings UI o `DEEZER_ARL` | — | Sesión Deezer Premium/HiFi (FLAC) |
| Proyecto | `/volume1/docker/music-harvester` (recomendado) | — (código en imagen) | Compose, cookies, `.env` |
| UI | `http://<ip-nas>:8085` | — | Angular + API vía nginx |

En contenedores **no** uses `/volumeN/...` en Settings ni en `MUSIC_PATH`; siempre montá la carpeta compartida del host sobre `/music` y `/cookies`. El volumen DSM (1, 2, 3…) solo importa en el lado host vía `MUSIC_HOST_PATH`.

---

## 1. Volumen de música (`/music`)

Music Harvester escribe la biblioteca en el filesystem. PostgreSQL guarda jobs, cola, settings y playlists; los archivos reales van a la ruta configurada en `MUSIC_PATH` (por defecto `/music` dentro del contenedor).

### Crear la carpeta compartida

1. **Panel de control → Carpeta compartida → Crear**
2. Nombre sugerido: `music`
3. Ruta resultante: `/volume1/music`, `/volume2/music`, etc. (cualquier volumen DSM)
4. Permisos: el usuario que ejecuta Docker debe poder **leer y escribir** en esta carpeta

### Montaje en Docker

En Synology **no** montes el código fuente sobre `/var/www/html`: eso tapa el `vendor/` que viene dentro de la imagen. El archivo `docker-compose.synology.yml` del repo solo bind-montea la biblioteca de música y las cookies; Postgres (`pg_data`) y Laravel `storage/` (`app_storage`) van en **volúmenes nombrados**:

```yaml
# docker-compose.synology.yml (incluido en el repo)
# MUSIC_HOST_PATH viene del .env — ej. /volume2/music
services:
  app:
    volumes: !override
      - ${MUSIC_HOST_PATH:-/volume1/music}:/music
      - ./cookies:/cookies:ro
      - app_storage:/var/www/html/storage
  # db (Postgres), worker, scheduler: ver docker-compose.yml + este override
```

En el `.env` del NAS:

```env
MUSIC_PATH=/music
MUSIC_HOST_PATH=/volume2/music
```

Podés cambiar `MUSIC_HOST_PATH` a cualquier `/volumeN/music` sin tocar Settings ni el código; dentro del contenedor sigue siendo `/music`.

> **¿Por qué volúmenes nombrados y no bind mounts para storage/DB?** En Synology las carpetas del host pertenecen al usuario de DSM, no a `www-data` (uid 33) del contenedor, y el `chown` suele fallar sobre bind mounts. Eso produce errores de *permission denied* en el log. Con volúmenes nombrados, Docker los inicializa y el entrypoint puede darles permiso a `www-data`. Postgres guarda los datos en `pg_data`.

Levantá el stack con ambos archivos:

```bash
docker compose -f docker-compose.yml -f docker-compose.synology.yml up -d --build
```

En **Container Manager → Proyecto**, podés indicar varios archivos compose en el asistente o usar el mismo comando por SSH.

### Estructura de archivos

Descargas one-shot (track/álbum):

```
/music/{artist}/{album}/{index} - {title}.{ext}
```

Playlists guardadas (sync):

```
/music/playlists/{id}-{slug}/
  {index} - {artist} - {title}.{ext}
  {id}-{slug}.m3u
```

El `.m3u` se regenera en cada sync (rutas relativas). Apuntá Navidrome (o Audio Station) a `/volume1/music` para indexar ambas estructuras. Los cambios remotos en playlists se detectan por polling (~5 min), no hay webhooks de Deezer/YouTube Music.

Ejemplo one-shot:

```
/volume1/music/queen/a-night-at-the-opera/01 - bohemian-rhapsody.mp3
```

Los nombres se normalizan con slugs (sin caracteres especiales). Audio Station indexa por carpetas; no hace falta una estructura extra.

### Verificación

```bash
# Dentro del worker
docker compose exec worker ls -la /music

# Desde la API (Settings)
curl -s http://<ip-nas>:8085/api/settings | jq '.data.music_path'
# Debe responder "/music"
```

En la UI: **Configuración → Ruta de música** debe mostrar `/music` (valor dentro del contenedor, no la ruta DSM).

---

## 2. Cookies de YouTube Music

Sin cookies de sesión, YouTube suele bloquear descargas con errores del tipo *“Sign in to confirm you're not a bot”*. Las playlists privadas o con restricciones de edad también requieren cookies válidas.

### Exportar cookies desde el navegador

1. Iniciá sesión en [music.youtube.com](https://music.youtube.com) con la cuenta que usás para escuchar.
2. Instalá una extensión que exporte formato **Netscape** (no JSON):
   - Recomendada: [Get cookies.txt LOCALLY](https://chromewebstore.google.com/detail/get-cookiestxt-locally/cclelndahbckbenkjhflpdbgdldlbecc) (Chrome / Edge / Brave)
3. Con la pestaña de YouTube Music activa, exportá cookies para el dominio **`.youtube.com`**
4. Guardá el archivo como `cookies.txt`

### Ubicación en el NAS

```bash
/volume1/docker/music-harvester/cookies/youtube/cookies.txt
# legacy (sigue funcionando):
/volume1/docker/music-harvester/cookies/cookies.txt
```

El repositorio incluye `cookies/youtube/.gitkeep`; **no** subas `cookies.txt` a git (contiene tokens de sesión).

### Montaje read-only

El `docker-compose.yml` monta `./cookies:/cookies:ro` en `app`, `worker` y `scheduler`. La app detecta el archivo vía Settings (`provider_youtube_music_cookies_path`) o env (`YOUTUBE_MUSIC_COOKIES_PATH` / `COOKIES_PATH`).

`yt-dlp` intenta **reescribir** el archivo de cookies al terminar (cookies refrescadas). Como el mount es solo lectura, `YtDlpDownloader` copia el archivo a `/tmp` antes de invocar `yt-dlp`. Las cookies actualizadas **no** persisten en el NAS; cuando caduquen, re-exportá desde el navegador.

---

## 2b. Deezer (FLAC)

Para descargas nativas en FLAC hace falta una cuenta **Deezer HiFi** y el cookie de sesión **ARL**.

1. Iniciá sesión en [deezer.com](https://www.deezer.com)
2. DevTools → Application → Cookies → copiá el valor de **`arl`**
3. En la UI: **Configuración → Deezer** → pegá el ARL y dejá modo **Nativo**
4. Alternativa: `DEEZER_ARL=...` en `.env` (nunca en git)

Sin ARL (o con modo **Híbrido**), Deezer resuelve metadata por API pública y descarga haciendo match en YouTube Music — **no es lossless**. Detalle: [providers.md](providers.md).

Formatos soportados: MP3 320 / M4A (YouTube Music) y **FLAC** / MP3 320 (Deezer nativo).

---

### Verificación de cookies YTM

```bash
# Archivo visible en el worker
docker compose exec worker ls -la /cookies/
docker compose exec worker head -3 /cookies/cookies.txt
# Primera línea típica: # Netscape HTTP Cookie File

# API / UI
curl -s http://<ip-nas>:8085/api/settings | jq '.data.provider_youtube_music_cookies_configured'
# true si el archivo existe y es legible
```

En **Configuración → YouTube Music**, el indicador de cookies debe estar en verde (**configuradas**).

### Mantenimiento

- Las cookies expiran; si vuelven a fallar descargas con error de bot o login, re-exportá y reiniciá el worker:
  ```bash
  docker compose restart worker
  ```
- Tras cambiar el archivo o el mount:
  ```bash
  docker compose up -d --force-recreate app worker scheduler
  ```
- Reintentá jobs fallidos desde la UI o con `POST /api/downloads/{id}/retry`

---

## 3. Media Indexing y Audio Station

Music Harvester **no** llama a APIs de DSM. Solo escribe archivos en `/volume1/music`; Synology los descubre con **Indexación multimedia** y **Audio Station** / **DS Audio** los muestra en la biblioteca.

### Indexación multimedia

1. **Panel de control → Indexación multimedia**
2. **Carpetas indexadas → Crear → Carpetas compartidas**
3. Seleccioná la carpeta `music` (`/volume1/music`)
4. Tipo de contenido: **Música**
5. Guardá y, si hace falta, **Reindexar** manualmente la carpeta

### Audio Station

1. Abrí **Audio Station** (o la app móvil **DS Audio**)
2. Comprobá que la biblioteca incluya la carpeta `music`
3. Tras una descarga grande (playlist/álbum), la indexación puede tardar unos minutos según el modelo del NAS

### Consejos

- Evitá duplicar la misma carpeta en varios paquetes con reglas distintas; una sola entrada en Indexación multimedia alcanza.
- Si un archivo nuevo no aparece, forzá reindexación de la carpeta `music` desde DSM antes de revisar la app.
- Formatos soportados por defecto: **MP3 320** y **M4A** (según configuración en la UI).

---

## 4. Deploy con Container Manager

### 4.1 Preparar el proyecto en el NAS

Por SSH (usuario admin o con sudo):

```bash
sudo mkdir -p /volume1/docker
cd /volume1/docker
sudo git clone git@github.com:nicolasmartinez0510/music-harvester.git
cd music-harvester
sudo chown -R $(whoami):users .
```

Ajustá la URL del remoto si usás GitLab u otro host.

### 4.2 Variables de entorno

```bash
cp .env.example .env
```

Editá `.env` en el NAS:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=http://<ip-o-hostname-nas>:8085
APP_PORT=8085

# Generar APP_KEY en el primer arranque (ver abajo)

MUSIC_PATH=/music
COOKIES_PATH=/cookies/cookies.txt
MUSIC_DEFAULT_FORMAT=mp3_320
MUSIC_MAX_CONCURRENCY=1
```

Colocá `cookies/cookies.txt` antes de levantar el worker.

### 4.3 Override de volúmenes para Synology

Usá el `docker-compose.synology.yml` del repo (no `docker-compose.dev.yml`, que es solo para desarrollo local). Ese archivo:

- Instala `vendor/` y el frontend **dentro de la imagen** al hacer `build`
- Bind-montea solo `/volume1/music` y `./cookies`
- Deja Postgres (`pg_data`) y `storage/` (`app_storage`) en **volúmenes nombrados** (permisos correctos)

**No** uses `docker-compose.dev.yml` en el NAS: monta el código fuente del host y requiere `composer install` local.

### 4.4 Primer arranque (SSH)

```bash
# Importante: bajar contenedores viejos si existían con el compose anterior
docker compose -f docker-compose.yml -f docker-compose.synology.yml down

docker compose -f docker-compose.yml -f docker-compose.synology.yml build --no-cache
docker compose -f docker-compose.yml -f docker-compose.synology.yml run --rm app php artisan key:generate
docker compose -f docker-compose.yml -f docker-compose.synology.yml up -d
```

Las **migraciones se corren solas** al arrancar el servicio `app` (crea las tablas `cache`, `jobs`, `sessions`, `download_jobs`, `settings`). El `worker` y el `scheduler` esperan a que `app` esté *healthy* antes de arrancar, así que no verás el error `no such table: cache`.

Verificá que `vendor/` existe **dentro** del contenedor (no en el host):

```bash
docker compose -f docker-compose.yml -f docker-compose.synology.yml exec worker ls -la vendor/autoload.php
docker compose -f docker-compose.yml -f docker-compose.synology.yml exec worker php artisan --version
```

Si `vendor/autoload.php` no existe, el entrypoint intenta `composer install` al arrancar; el build con `--no-cache` debería incluirlo de todas formas.

Comprobaciones:

```bash
curl -s http://localhost:8085/up          # health Laravel
curl -s http://localhost:8085/api/settings | jq .
docker compose exec worker yt-dlp --version
docker compose exec worker ffmpeg -version
```

### 4.5 Container Manager (UI)

1. **Container Manager → Proyecto → Crear**
2. **Nombre del proyecto:** `music-harvester`
3. **Ruta:** `/volume1/docker/music-harvester`
4. **Origen del compose:** usar `docker-compose.yml` y agregar `docker-compose.synology.yml` como override (o un único compose fusionado si preferís)
5. **Variables de entorno:** cargar desde `.env` o definir `APP_KEY`, `APP_PORT`, etc. en la UI
6. **Puerto:** mapear `8085 → 80` del servicio `nginx`
7. **Iniciar** el proyecto

Para actualizar tras un `git pull`:

```bash
docker compose -f docker-compose.yml -f docker-compose.synology.yml up -d --build
docker compose -f docker-compose.yml -f docker-compose.synology.yml restart worker
```

No uses `docker-compose.dev.yml` en el NAS, ni `down -v`: ese flag borra el volumen de Postgres.

### 4.6 Dedup de descargas ya existentes

A partir del índice `downloaded_tracks`, una playlist no vuelve a bajar un tema si ese archivo ya está registrado y sigue en disco. El M3U apunta al archivo del álbum con un path relativo. En la UI el estado es **Ya existe**. **Omitido** es otra cosa: el tema salió de la playlist remota.

Las descargas nuevas se indexan solas. La música que ya estaba en el NAS antes de ese cambio no entra hasta correr el backfill, una sola vez, después de desplegar.

1. En la Mac, `git push`. En la carpeta del proyecto del NAS, `git pull`.
2. Reconstruir. Al arrancar, `app` migra solo y crea la tabla:

```bash
docker compose -f docker-compose.yml -f docker-compose.synology.yml up -d --build
```

3. Esperá a que `app` esté healthy. El ARL de Deezer tiene que estar en Settings: el comando resuelve cada álbum o playlist contra el proveedor.
4. Ensayo, sin escribir el índice:

```bash
docker compose -f docker-compose.yml -f docker-compose.synology.yml exec app php artisan downloads:backfill-index --dry-run
```

5. Si el resumen cierra, indexá de verdad:

```bash
docker compose -f docker-compose.yml -f docker-compose.synology.yml exec app php artisan downloads:backfill-index
```

Tarda: una consulta por URL de job `done`, con 1 segundo de pausa entre cada una (`--sleep=0` la saca). Primero toma álbumes y temas sueltos, después playlists, para que el path que queda sea el del álbum. Un job que no se puede resolver se saltea y el resto sigue. El resumen dice cuántos se indexaron, cuántos ya estaban, cuántos jobs no tienen archivo y cuántos fallaron al resolver.

6. En la UI, **Sincronizar ahora** en la playlist. Los temas indexados quedan como **Ya existe** y no se vuelven a bajar.

No hace falta repetir el backfill en cada deploy. Solo si más adelante importás una biblioteca vieja que nunca pasó por este índice.

### 4.6.1 Portadas que faltan en lo ya descargado

Las descargas nuevas ya salen con carátula. Los archivos que quedaron en disco **sin** ninguna imagen no se corrigen solos. `downloads:repair-covers` recorre el índice Deezer (`downloaded_tracks`) y embebe `cover_xl` solo en esos. Si el tema ya tiene portada, aunque sea la equivocada (por ejemplo un thumbnail de YouTube), no lo toca.

Hace falta que el tema esté en el índice. Si la biblioteca es anterior, corré antes `downloads:backfill-index` (sección 4.6). El ARL de Deezer tiene que estar en Settings: el comando pide la portada a la API.

En la carpeta del proyecto del NAS (`/volume1/docker/music-harvester`):

1. `git pull` de este cambio y reconstruir. El detector de carátula va en la imagen; sin rebuild el comando no lo encuentra.

```bash
docker compose -f docker-compose.yml -f docker-compose.synology.yml up -d --build
```

2. Esperá a que `app` esté healthy.
3. Ensayo, sin escribir archivos:

```bash
docker compose -f docker-compose.yml -f docker-compose.synology.yml exec app php artisan downloads:repair-covers --dry-run
```

4. Si el listado cierra, aplicalo:

```bash
docker compose -f docker-compose.yml -f docker-compose.synology.yml exec app php artisan downloads:repair-covers
```

El comando imprime `Loading Deezer index...` al instante y después una línea `[n/total] checking ...` por archivo. Si no aparece ni esa primera línea, el proceso no llegó a arrancar.

Si Deezer limita las consultas, agregá `--sleep=1`. El resumen final dice cuántos se escanearon, cuántos ya tenían portada, cuántos se repararon y cuántos siguieron sin ella. No hace falta repetirlo en cada deploy.

### 4.7 Servicios del stack

| Servicio | Función |
|----------|---------|
| `app` | PHP-FPM + Laravel API |
| `worker` | Cola de descargas (`queue:work`) + yt-dlp + ffmpeg |
| `scheduler` | Tareas programadas (`schedule:work`) |
| `nginx` | UI Angular + proxy `/api` → Laravel (mismo origen, sin CORS) |

### 4.8 Carga del NAS

- Dejá `MUSIC_MAX_CONCURRENCY=1` en modelos con CPU limitada (ARM o entry-level).
- Las descargas de playlist son secuenciales por job; evitá lanzar muchas descargas grandes a la vez.
- Monitoreá uso de CPU en **Administrador de recursos** durante la primera playlist completa.

### 4.9 Actualizar yt-dlp

YouTube cambia a menudo; conviene reconstruir la imagen periódicamente:

```bash
docker compose -f docker-compose.yml -f docker-compose.synology.yml build --no-cache
docker compose -f docker-compose.yml -f docker-compose.synology.yml up -d --force-recreate app worker scheduler
```

Alternativa puntual dentro del worker (se pierde al rebuild):

```bash
docker compose exec worker pip3 install --break-system-packages -U "yt-dlp[default]"
```

---

## Solución de problemas

| Síntoma | Causa probable | Acción |
|---------|----------------|--------|
| `Sign in to confirm you're not a bot` | Sin cookies o cookies vencidas | Re-exportar `cookies.txt`, reiniciar worker |
| `cookies_configured: false` | Mount incorrecto o archivo ausente | Verificar `./cookies/cookies.txt` y `COOKIES_PATH` |
| Archivos no aparecen en Audio Station | Carpeta no indexada | Agregar `/volume1/music` en Indexación multimedia |
| `vendor/autoload.php` no encontrado | Compose viejo montaba `.:/var/www/html` y tapaba la imagen | `git pull`, `down`, `build --no-cache`, levantar con `docker-compose.synology.yml` (sin `docker-compose.dev.yml`) |
| `no such table: cache` / `jobs` / `sessions` | Postgres vacío o migraciones sin correr | `exec app php artisan migrate --force` (o recrear `app`: el entrypoint migra al arrancar) |
| `laravel.log … Permission denied` | `storage/` bind-mounteado desde el host con dueño ≠ `www-data` | Usar volumen nombrado `app_storage` (compose actualizado): `down -v` → `up -d --build` |
| UI carga pero API falla | `APP_KEY` vacío o DB sin migrar | `key:generate` + recrear `app` (migra solo); verificar servicio `db` healthy |
| Descargas muy lentas | Concurrencia alta en NAS débil | `MUSIC_MAX_CONCURRENCY=1` en Settings |
| Borrar en Descargas no saca el archivo del disco | El worker viejo escribía como root y php-fpm (`www-data`) no puede borrar esas carpetas | Rebuild y recrear `app` + `worker`. El entrypoint abre los directorios de `/music` y el worker nuevo corre como `www-data` |

Logs del worker y de la app (con `storage/` en volumen nombrado, leelos vía `docker compose`):

```bash
docker compose logs -f worker
docker compose exec app tail -f storage/logs/laravel.log
```

---

## Uso personal

Music Harvester está pensado para **uso personal** en tu propia biblioteca NAS. No bypass DRM de servicios de streaming con licencia distinta (p. ej. Spotify oficial). El provider MVP es **YouTube Music** vía URL pública y cookies de tu propia sesión.
