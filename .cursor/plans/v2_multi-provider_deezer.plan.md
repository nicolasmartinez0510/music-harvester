---
name: v2 Multi-Provider Deezer
overview: "Extiende el plan v2 con playlists guardadas, multi-proveedor (YTM + Deezer), credenciales por proveedor, e imagen Docker optimizada para Synology (build rápido o pull pre-built)."
todos:
  - id: v2-docker-slim
    content: "Imagen Docker liviana: split app/worker, frontend pre-built, base cacheable, deploy Synology sin build local"
    status: pending
  - id: v2-1a-playlists
    content: "v2.1a: Playlists guardadas YTM (schema, sync job, API, UI, scheduler) — plan original"
    status: pending
  - id: v2-1b-multi-provider
    content: "v2.1b: Refactor settings a credenciales por proveedor; cookies/youtube + cookies/deezer; GET /api/providers; UI settings por tabs"
    status: pending
  - id: v2-2-deezer-hybrid
    content: "v2.2: DeezerHybridProvider — resolve Deezer, YoutubeMusicMatcher, download vía yt-dlp; playlists guardadas Deezer"
    status: pending
  - id: v2-3-deezer-native
    content: "v2.3: DeezerNativeProvider — deezer-py + ARL, FLAC/320 Premium, toggle provider_deezer_mode=native"
    status: pending
  - id: v2-docs-providers
    content: "Docs: exportar cookies YTM, ARL Deezer, volúmenes Synology por proveedor"
    status: pending
isProject: false
---

# Music Harvester v2 — Playlists + Multi-proveedor (YTM + Deezer)

Plan consolidado. Ver sección **Imagen Docker liviana (Synology)** para optimización de build en NAS.

Extiende [`.cursor/plans/v2_playlists_sync_a9010a47.plan.md`](v2_playlists_sync_a9010a47.plan.md) con soporte Deezer, configuración por proveedor, e imagen Docker optimizada.

## Orden de implementación

0. **v2.0-docker** — Split app/worker, frontend off-NAS, base cacheable, compose Synology pull-only *(beneficio inmediato en Synology)*
1. **v2.1a** — Playlists guardadas YTM
2. **v2.1b** — Multi-proveedor settings + cookies por carpeta
3. **v2.2** — Deezer híbrido (match YT)
4. **v2.3** — Deezer nativo (ARL + deezer-py)
5. **Docs Synology** — pull vs build, cookies, `/music`

## Imagen Docker liviana (Synology) — resumen

**Problema:** el [`Dockerfile`](../../Dockerfile) actual buildea Node + npm + Deno + pip + compila PHP en una sola imagen (~20–40 min en NAS).

**Solución:**

1. **Dos imágenes:** `app` (PHP-FPM slim) vs `worker` (yt-dlp + ffmpeg + Deno)
2. **Frontend pre-built** en laptop/CI — el NAS no corre `npm`
3. **Base image cacheable** publicada en registry (GHCR/Docker Hub)
4. **Synology solo hace `docker pull`** — `docker-compose.synology.yml` sin `build:`

**Objetivo:** deploy en NAS en ~3–5 min (pull) vs ~30–40 min (build local).

Detalle completo en el plan maestro (mismas secciones: Deezer, credenciales, API, etc.).
