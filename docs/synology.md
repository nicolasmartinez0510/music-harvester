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
| Biblioteca | `/volume1/music` | `/music` | Archivos descargados (Audio Station) |
| Cookies YTM | `…/music-harvester/cookies/cookies.txt` | `/cookies/cookies.txt` | Sesión de YouTube Music |
| Proyecto | `/volume1/docker/music-harvester` (recomendado) | `/var/www/html` | Código, SQLite, logs |
| UI | `http://<ip-nas>:8085` | — | Angular + API vía nginx |

En contenedores **no** uses `/volume1/...`; siempre montá la carpeta compartida del host sobre `/music` y `/cookies`.

---

## 1. Volumen de música (`/music`)

Music Harvester escribe la biblioteca en el filesystem. SQLite solo guarda jobs y configuración; los archivos reales van a la ruta configurada en `MUSIC_PATH` (por defecto `/music` dentro del contenedor).

### Crear la carpeta compartida

1. **Panel de control → Carpeta compartida → Crear**
2. Nombre sugerido: `music`
3. Ruta resultante: `/volume1/music` (o `/volume2/music` si usás otro volumen)
4. Permisos: el usuario que ejecuta Docker debe poder **leer y escribir** en esta carpeta

### Montaje en Docker

En Synology **no** montes el código fuente sobre `/var/www/html`: eso tapa el `vendor/` que viene dentro de la imagen. El archivo `docker-compose.synology.yml` del repo ya resetea los volúmenes y solo persiste datos:

```yaml
# docker-compose.synology.yml (incluido en el repo)
services:
  app:
    volumes: !override
      - /volume1/music:/music
      - ./cookies:/cookies:ro
      - ./database:/var/www/html/database
      - ./storage:/var/www/html/storage
  # worker, scheduler, nginx: ver archivo completo
```

Levantá el stack con ambos archivos:

```bash
docker compose -f docker-compose.yml -f docker-compose.synology.yml up -d --build
```

En **Container Manager → Proyecto**, podés indicar varios archivos compose en el asistente o usar el mismo comando por SSH.

### Estructura de archivos

Las descargas se organizan así:

```
/music/{artist}/{album}/{index} - {title}.{ext}
```

Ejemplo:

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
/volume1/docker/music-harvester/cookies/cookies.txt
```

El repositorio incluye `cookies/.gitkeep`; **no** subas `cookies.txt` a git (contiene tokens de sesión).

### Montaje read-only

El `docker-compose.yml` monta `./cookies:/cookies:ro` en `app`, `worker` y `scheduler`. La app detecta el archivo en `/cookies/cookies.txt` vía `COOKIES_PATH`.

`yt-dlp` intenta **reescribir** el archivo de cookies al terminar (cookies refrescadas). Como el mount es solo lectura, `YtDlpDownloader` copia el archivo a `/tmp` antes de invocar `yt-dlp`. Las cookies actualizadas **no** persisten en el NAS; cuando caduquen, re-exportá desde el navegador.

### Verificación

```bash
# Archivo visible en el worker
docker compose exec worker ls -la /cookies/
docker compose exec worker head -3 /cookies/cookies.txt
# Primera línea típica: # Netscape HTTP Cookie File

# API / UI
curl -s http://<ip-nas>:8085/api/settings | jq '.data.cookies_configured'
# true si el archivo existe y es legible
```

En **Configuración** de la UI, el indicador de cookies debe estar en verde (**detectadas**). Si dice **no detectadas**, revisá el mount y la ruta en **Ruta de cookies** (`/cookies/cookies.txt`).

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
- Monta solo `/music`, cookies, `database/` y `storage/` — **no** el proyecto entero

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

### 4.6 Servicios del stack

| Servicio | Función |
|----------|---------|
| `app` | PHP-FPM + Laravel API |
| `worker` | Cola de descargas (`queue:work`) + yt-dlp + ffmpeg |
| `scheduler` | Tareas programadas (`schedule:work`) |
| `nginx` | UI Angular + proxy `/api` → Laravel (mismo origen, sin CORS) |

### 4.7 Carga del NAS

- Dejá `MUSIC_MAX_CONCURRENCY=1` en modelos con CPU limitada (ARM o entry-level).
- Las descargas de playlist son secuenciales por job; evitá lanzar muchas descargas grandes a la vez.
- Monitoreá uso de CPU en **Administrador de recursos** durante la primera playlist completa.

### 4.8 Actualizar yt-dlp

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
| `no such table: cache` / `jobs` / `sessions` | Base SQLite vacía, migraciones sin correr | `exec app php artisan migrate --force` (o recrear `app`: el entrypoint migra al arrancar) |
| UI carga pero API falla | `APP_KEY` vacío o SQLite sin migrar | `key:generate` + recrear `app` (migra solo) |
| Descargas muy lentas | Concurrencia alta en NAS débil | `MUSIC_MAX_CONCURRENCY=1` en Settings |

Logs del worker:

```bash
docker compose logs -f worker
tail -f storage/logs/laravel.log
```

---

## Uso personal

Music Harvester está pensado para **uso personal** en tu propia biblioteca NAS. No bypass DRM de servicios de streaming con licencia distinta (p. ej. Spotify oficial). El provider MVP es **YouTube Music** vía URL pública y cookies de tu propia sesión.
