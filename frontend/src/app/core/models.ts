export type DownloadStatus = 'pending' | 'running' | 'done' | 'failed';
export type AudioFormat = 'mp3_320' | 'm4a' | 'flac';
export type DeezerMode = 'native' | 'hybrid';
export type ProviderName = 'youtube_music' | 'deezer' | 'auto';
export type PlaylistSyncStatus = 'idle' | 'running' | 'done' | 'failed';
export type PlaylistTrackStatus = 'pending' | 'downloaded' | 'failed' | 'skipped';

export interface DownloadJob {
  id: number;
  provider: string;
  url: string;
  kind: string;
  title: string | null;
  artist: string | null;
  status: DownloadStatus;
  progress: number;
  error: string | null;
  destination_path: string | null;
  files_present: boolean;
  format: AudioFormat;
  created_at: string;
  updated_at: string;
}

export interface PlaylistTrackCounts {
  total: number;
  downloaded: number;
  pending: number;
  failed: number;
  skipped: number;
}

export interface SavedPlaylist {
  id: number;
  provider: string;
  url: string;
  title: string | null;
  sync_enabled: boolean;
  sync_interval_minutes: number;
  default_format: AudioFormat | null;
  last_synced_at: string | null;
  last_sync_status: PlaylistSyncStatus;
  last_sync_error: string | null;
  counts: PlaylistTrackCounts;
  created_at: string;
  updated_at: string;
}

export interface SavedPlaylistTrack {
  id: number;
  external_id: string;
  title: string;
  artist: string | null;
  position: number;
  status: PlaylistTrackStatus;
  file_path: string | null;
  last_error: string | null;
  first_seen_at: string | null;
  downloaded_at: string | null;
}

export interface SavedPlaylistDetail extends SavedPlaylist {
  tracks: SavedPlaylistTrack[];
}

export interface Settings {
  music_path: string;
  default_format: AudioFormat;
  max_concurrency: number;
  enabled_providers: string;
  provider_youtube_music_cookies_path: string | null;
  provider_youtube_music_cookies_configured: boolean;
  provider_deezer_arl_configured: boolean;
  provider_deezer_mode: DeezerMode;
  cookies_path: string | null;
  cookies_configured: boolean;
}

export interface ProviderInfo {
  name: string;
  configured: boolean;
  qualities: AudioFormat[];
  has_catalog: boolean;
  mode?: DeezerMode;
}

export type CatalogType = 'track' | 'album' | 'artist' | 'playlist' | 'all';

export interface CatalogHit {
  id: string;
  type: Exclude<CatalogType, 'all'>;
  title: string;
  subtitle: string | null;
  cover_url: string | null;
  canonical_url: string | null;
  nb_tracks: number | null;
  release_date?: string | null;
  fans?: number | null;
}

export interface CatalogArtist {
  id: string;
  type: 'artist';
  title: string;
  name: string;
  cover_url: string | null;
  canonical_url: string | null;
  nb_fans: number;
  description?: string | null;
  top_tracks: CatalogHit[];
  albums: CatalogHit[];
}

export interface CatalogAlbum {
  id: string;
  type: 'album';
  title: string;
  subtitle: string | null;
  artist_name: string | null;
  cover_url: string | null;
  canonical_url: string | null;
  nb_tracks: number;
  tracks: CatalogHit[];
}

export interface CatalogPlaylist {
  id: string;
  type: 'playlist';
  title: string;
  subtitle: string | null;
  creator_name: string | null;
  cover_url: string | null;
  canonical_url: string | null;
  nb_tracks: number;
  tracks: CatalogHit[];
}

export interface ApiResource<T> {
  data: T;
}

export interface ApiValidationError {
  message?: string;
  errors?: Record<string, string[]>;
}

export const AUDIO_FORMATS: { value: AudioFormat; label: string }[] = [
  { value: 'mp3_320', label: 'MP3 320 kbps' },
  { value: 'm4a', label: 'M4A (AAC)' },
  { value: 'flac', label: 'FLAC lossless' },
];

export const PROVIDER_LABELS: Record<string, string> = {
  youtube_music: 'YouTube Music',
  deezer: 'Deezer',
  auto: 'Auto',
};

export const STATUS_LABELS: Record<DownloadStatus, string> = {
  pending: 'Pendiente',
  running: 'Descargando',
  done: 'Completado',
  failed: 'Fallido',
};

export const PLAYLIST_SYNC_LABELS: Record<PlaylistSyncStatus, string> = {
  idle: 'Sin sync',
  running: 'Sincronizando',
  done: 'OK',
  failed: 'Error',
};

export const PLAYLIST_TRACK_LABELS: Record<PlaylistTrackStatus, string> = {
  pending: 'Pendiente',
  downloaded: 'Descargado',
  failed: 'Fallido',
  skipped: 'Omitido',
};

export const CATALOG_TYPE_LABELS: Record<CatalogType, string> = {
  all: 'Todo',
  track: 'Tracks',
  album: 'Álbumes',
  artist: 'Artistas',
  playlist: 'Playlists',
};

export function detectProviderFromUrl(url: string): ProviderName | null {
  if (/deezer\.com|link\.deezer\.com/i.test(url)) {
    return 'deezer';
  }
  if (/youtube\.com|youtu\.be|music\.youtube\.com/i.test(url)) {
    return 'youtube_music';
  }
  return null;
}
