export type DownloadStatus = 'pending' | 'running' | 'done' | 'failed';
export type AudioFormat = 'mp3_320' | 'm4a';

export interface DownloadJob {
  id: number;
  provider: string;
  url: string;
  kind: string;
  status: DownloadStatus;
  progress: number;
  error: string | null;
  destination_path: string | null;
  format: AudioFormat;
  created_at: string;
  updated_at: string;
}

export interface Settings {
  music_path: string;
  default_format: AudioFormat;
  max_concurrency: number;
  cookies_path: string | null;
  cookies_configured: boolean;
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
];

export const STATUS_LABELS: Record<DownloadStatus, string> = {
  pending: 'Pendiente',
  running: 'Descargando',
  done: 'Completado',
  failed: 'Fallido',
};
