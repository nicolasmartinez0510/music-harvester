# Providers (YouTube Music + Deezer)

## Spike: cliente FLAC para Deezer

Evaluamos clientes que lean ARL y descarguen FLAC:

| Cliente | Estado | Notas |
|---------|--------|-------|
| **streamrip** (`rip`) | **Elegido** | CLI activo, `pip install streamrip`, quality 0/1/2 (128 / 320 / FLAC), ARL en config |
| orpheusdl | Alternativa viable | Más modular; setup más pesado en Docker |
| deezer-py / deemix | No | deemix poco mantenido / roto a menudo en 2025–2026 |

**Decisión:** el worker invoca `rip url <deezer-url>` con un `config.toml` temporal (HOME override) que incluye el ARL y `quality = 2` para FLAC. Si la cuenta no es HiFi, streamrip puede degradar a 320 con `lower_quality_if_not_available = true`.

yt-dlp **no** se usa para audio Deezer (extractor removido). Solo entra en el **modo híbrido**.

## Credenciales

### YouTube Music

1. Iniciar sesión en music.youtube.com
2. Exportar cookies Netscape (extensión “Get cookies.txt LOCALLY”)
3. Guardar como `cookies/youtube/cookies.txt` (o legacy `cookies/cookies.txt`)
4. Settings → YouTube Music → ruta `/cookies/youtube/cookies.txt` (o dejar el default de env)

### Deezer ARL

1. Iniciar sesión en [deezer.com](https://www.deezer.com) (cuenta **Premium / HiFi** para FLAC)
2. DevTools → Application → Cookies → `https://www.deezer.com` → cookie **`arl`**
3. Copiar el valor (~192 caracteres) en Settings → Deezer → ARL
4. Alternativa: variable de entorno `DEEZER_ARL` (no commitear)

El ARL caduca o se invalida al cambiar contraseña / cerrar sesión. Re-pegar uno fresco cuando fallen las descargas nativas.

## Modos Deezer

| Modo | Cuándo | Calidad |
|------|--------|---------|
| `native` (default) | ARL configurado | FLAC o MP3 320 vía streamrip |
| `hybrid` | Sin ARL, o mode=hybrid explícito | Metadata Deezer + match `artista título` en YouTube + yt-dlp — **no es lossless** |

Riesgos del híbrido: covers/remixes incorrectos, calidad de YouTube, rate-limit.

## API

```bash
curl -s http://localhost:8085/api/providers | jq
# [{ name, configured, qualities, has_catalog, mode? }, ...]

curl -s -X POST http://localhost:8085/api/downloads \
  -H 'Content-Type: application/json' \
  -d '{"url":"https://www.deezer.com/track/3135556","format":"flac"}'
```

Override de proveedor: `"provider": "deezer"` | `"youtube_music"` | `"auto"`.

## Agregar un provider nuevo

1. Implementar `MusicProvider` (`supports` / `resolve` / `download`)
2. Opcional: implementar `CatalogSource` para búsqueda (épica 3)
3. Registrar con tag `music.providers` (y `music.catalog_sources`) en `MusicHarvesterServiceProvider`
4. Añadir keys de settings / env si hace falta
5. Incluir el nombre en `enabled_providers`

No hace falta tocar el código de YouTube Music ni el de Deezer.
