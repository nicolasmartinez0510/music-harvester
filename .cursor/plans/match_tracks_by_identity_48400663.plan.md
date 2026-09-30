---
name: Match tracks by identity
overview: El sync de playlists y la descarga de temas o álbumes dejan de tratar como nuevo un tema solo porque el id de Deezer cambió de edición. Reutilizan el archivo si ya hay uno del mismo artista y título, y solo desambiguan por año cuando hay más de uno.
todos:
  - id: schema-year
    content: Migración release_year y persistirlo en upsert desde Track (Deezer release_date)
    status: completed
  - id: identity-lookup
    content: "findPresentByIdentity: artista+título, año solo si hay más de un archivo presente"
    status: completed
  - id: sync-job
    content: Usar el lookup en playlist sync y en descargas de tema o álbum; sin id, ir directo a artista y título
    status: completed
  - id: backfill-year
    content: Completar release_year de filas existentes leyendo el tag date del archivo
    status: completed
  - id: tests
    content: Tests del repositorio y de los jobs para reutilizar vs descargar
    status: completed
isProject: false
---

# Reutilizar temas por artista y título

Hoy el match no mira el álbum. En [`ProcessPlaylistSyncJob`](backend/app/Jobs/ProcessPlaylistSyncJob.php) y [`ProcessDownloadJob`](backend/app/Jobs/ProcessDownloadJob.php) un tema se salta solo si [`findPresent`](backend/app/Infrastructure/Persistence/EloquentDownloadedTrackRepository.php) encuentra la misma fila de `downloaded_tracks` por `user_id + provider + external_id`. En Deezer ese id es de una edición concreta: el mismo tema en el álbum estándar y en el deluxe son ids distintos, así que se vuelve a descargar.

## Regla

Si el proveedor mandó id, se busca primero por ese id. Si no hay id, o no coincide:

1. Buscar en el índice del usuario archivos presentes cuyo artista y título coincidan, sin mirar provider ni álbum. Comparación case-insensitive, con trim.
2. Cero resultados: descargar.
3. Un resultado: marcarlo `existing` y reutilizar ese `file_path`, aunque el año no coincida. Cubre estándar vs deluxe cuando la biblioteca tiene una sola copia.
4. Más de uno: filtrar por año de lanzamiento.
   - Si alguno coincide con ese año, reutilizar uno de esos.
   - Si todos tienen otro año conocido, descargar (otro lanzamiento).
   - Si no hay año en el tema entrante o en los candidatos, reutilizar uno. No se puede distinguir y no queremos bajar otra copia.

Artista vacío no entra en este match.

Los títulos se comparan tal cual están guardados. Un sufijo distinto, por ejemplo `(Album Version)`, sigue siendo otro nombre.

Un tema de un álbum que ya se bajó suelto desde otra edición se omite. El resto del álbum sigue. Para tener la copia de este álbum hay que borrar antes esa descarga individual.

## Año en el índice

`downloaded_tracks` no tiene año. Agregar `release_year` (smallint, nullable).

- En [`Track`](backend/app/Domain/Music/Models/Track.php), un `?int $releaseYear` opcional.
- En [`DeezerProvider::mapTrack`](backend/app/Infrastructure/Providers/Deezer/DeezerProvider.php), tomar el año de `release_date` del track, o si no está, de `album.release_date`.
- [`DownloadedTrackRepository::upsert`](backend/app/Domain/Music/Contracts/DownloadedTrackRepository.php) persiste ese año en las descargas de álbum, de playlist y en el backfill, para que el desempate exista de ahora en más.

Para las filas que ya están indexadas, un backfill lee el tag `date` que el enricher de Deezer ya escribe en el archivo ([`MutagenAudioTagWriter`](backend/app/Infrastructure/Metadata/MutagenAudioTagWriter.php)) y completa `release_year` cuando el tag trae un año. Sin eso, dos copias viejas del mismo tema no se pueden separar por año.

## Dónde vive el lookup

[`DownloadedTrackLookup`](backend/app/Application/IndexDownloadedTracks/DownloadedTrackLookup.php) aplica los tres filtros. Si reutiliza, no crea una segunda fila de índice con el id de la otra edición. Si el proveedor no manda id, la clave de índice es `identity:` más un hash de artista, título y año.

## Tests

- Repositorio: un hit reutiliza; varios hits se achican por año; un solo hit ignora el año; archivo ausente no cuenta.
- Playlist sync y descarga de álbum: id distinto, mismo artista y título, no llama a `download`. Sin id de proveedor, va directo a artista y título. Varios homónimos con otro año sí descargan.
