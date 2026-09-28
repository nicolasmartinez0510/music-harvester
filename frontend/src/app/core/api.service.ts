import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';

import {
  ApiResource,
  AudioFormat,
  CatalogAlbum,
  CatalogArtist,
  CatalogHit,
  CatalogPlaylist,
  CatalogType,
  DeezerMode,
  DownloadJob,
  ProviderInfo,
  ProviderName,
  SavedPlaylist,
  SavedPlaylistDetail,
  Settings,
} from './models';

export interface CreateDownloadPayload {
  url: string;
  format?: AudioFormat;
  provider?: ProviderName | null;
}

export interface UpdateSettingsPayload {
  music_path?: string;
  default_format?: AudioFormat;
  max_concurrency?: number;
  enabled_providers?: string;
  provider_youtube_music_cookies_path?: string | null;
  provider_deezer_arl?: string | null;
  provider_deezer_mode?: DeezerMode;
  cookies_path?: string | null;
}

export interface CreatePlaylistPayload {
  url: string;
  sync_now?: boolean;
  sync_enabled?: boolean;
  sync_interval_minutes?: number;
}

export interface UpdatePlaylistPayload {
  sync_enabled?: boolean;
  sync_interval_minutes?: number;
  default_format?: AudioFormat | null;
}

@Injectable({ providedIn: 'root' })
export class ApiService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = '/api';

  listDownloads(limit = 50): Observable<DownloadJob[]> {
    return this.http
      .get<ApiResource<DownloadJob[]>>(`${this.baseUrl}/downloads`, {
        params: { limit: String(limit) },
      })
      .pipe(map((response) => response.data));
  }

  createDownload(payload: CreateDownloadPayload): Observable<DownloadJob> {
    return this.http
      .post<ApiResource<DownloadJob>>(`${this.baseUrl}/downloads`, payload)
      .pipe(map((response) => response.data));
  }

  retryDownload(id: number): Observable<DownloadJob> {
    return this.http
      .post<ApiResource<DownloadJob>>(`${this.baseUrl}/downloads/${id}/retry`, {})
      .pipe(map((response) => response.data));
  }

  deleteDownload(id: number): Observable<void> {
    return this.http.delete<void>(`${this.baseUrl}/downloads/${id}`);
  }

  clearDownloads(): Observable<{ deleted: number }> {
    return this.http.delete<{ deleted: number }>(`${this.baseUrl}/downloads`);
  }

  listPlaylists(): Observable<SavedPlaylist[]> {
    return this.http
      .get<ApiResource<SavedPlaylist[]>>(`${this.baseUrl}/playlists`)
      .pipe(map((response) => response.data));
  }

  getPlaylist(id: number): Observable<SavedPlaylistDetail> {
    return this.http
      .get<ApiResource<SavedPlaylistDetail>>(`${this.baseUrl}/playlists/${id}`)
      .pipe(map((response) => response.data));
  }

  createPlaylist(payload: CreatePlaylistPayload): Observable<SavedPlaylist> {
    return this.http
      .post<ApiResource<SavedPlaylist>>(`${this.baseUrl}/playlists`, payload)
      .pipe(map((response) => response.data));
  }

  updatePlaylist(id: number, payload: UpdatePlaylistPayload): Observable<SavedPlaylist> {
    return this.http
      .put<ApiResource<SavedPlaylist>>(`${this.baseUrl}/playlists/${id}`, payload)
      .pipe(map((response) => response.data));
  }

  deletePlaylist(id: number): Observable<void> {
    return this.http.delete<void>(`${this.baseUrl}/playlists/${id}`);
  }

  syncPlaylist(id: number): Observable<SavedPlaylist> {
    return this.http
      .post<ApiResource<SavedPlaylist>>(`${this.baseUrl}/playlists/${id}/sync`, {})
      .pipe(map((response) => response.data));
  }

  getSettings(): Observable<Settings> {
    return this.http
      .get<ApiResource<Settings>>(`${this.baseUrl}/settings`)
      .pipe(map((response) => response.data));
  }

  updateSettings(payload: UpdateSettingsPayload): Observable<Settings> {
    return this.http
      .put<ApiResource<Settings>>(`${this.baseUrl}/settings`, payload)
      .pipe(map((response) => response.data));
  }

  getProviders(): Observable<ProviderInfo[]> {
    return this.http
      .get<ApiResource<ProviderInfo[]>>(`${this.baseUrl}/providers`)
      .pipe(map((response) => response.data));
  }

  searchCatalog(params: {
    provider: string;
    q: string;
    type?: CatalogType;
    limit?: number;
    index?: number;
  }): Observable<CatalogHit[]> {
    const query: Record<string, string> = {
      provider: params.provider,
      q: params.q,
    };
    if (params.type) {
      query['type'] = params.type;
    }
    if (params.limit != null) {
      query['limit'] = String(params.limit);
    }
    if (params.index != null) {
      query['index'] = String(params.index);
    }

    return this.http
      .get<ApiResource<CatalogHit[]>>(`${this.baseUrl}/catalog/search`, { params: query })
      .pipe(map((response) => response.data));
  }

  getCatalogArtist(provider: string, id: string): Observable<CatalogArtist> {
    return this.http
      .get<ApiResource<CatalogArtist>>(`${this.baseUrl}/catalog/${provider}/artists/${id}`)
      .pipe(map((response) => response.data));
  }

  getCatalogAlbum(provider: string, id: string): Observable<CatalogAlbum> {
    return this.http
      .get<ApiResource<CatalogAlbum>>(`${this.baseUrl}/catalog/${provider}/albums/${id}`)
      .pipe(map((response) => response.data));
  }

  getCatalogPlaylist(provider: string, id: string): Observable<CatalogPlaylist> {
    return this.http
      .get<ApiResource<CatalogPlaylist>>(`${this.baseUrl}/catalog/${provider}/playlists/${id}`)
      .pipe(map((response) => response.data));
  }
}
