---
name: v2 Multi-Provider Deezer
overview: REEMPLAZADO. Este índice apilaba playlists + Deezer + Docker. Usar las tres épicas separadas; Docker slim fuera de alcance.
todos:
  - id: superseded
    content: No implementar este plan — ver v2_providers, v2_playlists_sync y v2_content_manager
    status: cancelled
isProject: false
---

# REEMPLAZADO — no implementar

Este archivo era un índice corto que apilaba:

1. Imagen Docker liviana (Synology)
2. Playlists guardadas YTM
3. Multi-proveedor settings
4. Deezer híbrido
5. Deezer nativo (ARL + FLAC)

sin separar épicas y sin administrador de contenido.

## Usar en su lugar

| Épica | Plan |
|-------|------|
| 1 — Proveedores + Deezer FLAC | [`v2_providers.plan.md`](v2_providers.plan.md) |
| 2 — Sync de playlists | [`v2_playlists_sync_a9010a47.plan.md`](v2_playlists_sync_a9010a47.plan.md) |
| 3 — Administrador de contenido | [`v2_content_manager.plan.md`](v2_content_manager.plan.md) |

- **Docker slim:** fuera de las tres épicas (ya tratado / no forma parte de este recorte).
- **FLAC nativo:** ahora es el camino principal de la épica 1, no un “v2.3” al final de un stack híbrido.

Contexto de la separación: plan maestro *Tres epicas v2*.
