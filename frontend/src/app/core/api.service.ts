import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';

import {
  ApiResource,
  AudioFormat,
  AuthUser,
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
  email_verification_enabled?: boolean;
  metadata_enrich_enabled?: boolean;
  metadata_embed_cover?: boolean;
  metadata_embed_lyrics?: boolean;
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

  getLibrary(
    provider: string,
    kind: 'artists' | 'albums' | 'tracks' | 'playlists',
    params?: { limit?: number; index?: number },
  ): Observable<CatalogHit[]> {
    const query: Record<string, string> = {};
    if (params?.limit != null) {
      query['limit'] = String(params.limit);
    }
    if (params?.index != null) {
      query['index'] = String(params.index);
    }

    return this.http
      .get<ApiResource<CatalogHit[]>>(`${this.baseUrl}/library/${provider}/${kind}`, {
        params: query,
      })
      .pipe(map((response) => response.data));
  }

  listUsers(): Observable<AuthUser[]> {
    return this.http
      .get<ApiResource<AuthUser[]>>(`${this.baseUrl}/admin/users`)
      .pipe(map((response) => response.data));
  }

  deleteUser(id: number): Observable<void> {
    return this.http.delete<void>(`${this.baseUrl}/admin/users/${id}`);
  }

  approveServerStorage(id: number): Observable<AuthUser> {
    return this.http
      .post<ApiResource<AuthUser>>(`${this.baseUrl}/admin/users/${id}/approve-server-storage`, {})
      .pipe(map((response) => response.data));
  }

  updateDeezerArl(arl: string): Observable<void> {
    return this.http.put<unknown>(`${this.baseUrl}/settings/providers/deezer`, { arl }).pipe(map(() => undefined));
  }

  uploadYoutubeCookies(file: File): Observable<void> {
    const body = new FormData();
    body.append('cookies', file);
    return this.http
      .post<unknown>(`${this.baseUrl}/settings/providers/youtube-music/cookies`, body)
      .pipe(map(() => undefined));
  }

  updatePreferences(payload: {
    download_destination?: 'direct' | 'server';
    avatar_id?: string;
  }): Observable<AuthUser> {
    return this.http
      .put<ApiResource<AuthUser>>(`${this.baseUrl}/me/preferences`, payload)
      .pipe(map((response) => response.data));
  }

  listFavoriteArtists(): Observable<FavoriteArtistPayload[]> {
    return this.http
      .get<ApiResource<FavoriteArtistPayload[]>>(`${this.baseUrl}/favorites/artists`)
      .pipe(map((response) => response.data));
  }

  toggleFavoriteArtist(artist: FavoriteArtistPayload): Observable<boolean> {
    return this.http
      .post<ApiResource<{ favorited: boolean }>>(`${this.baseUrl}/favorites/artists/toggle`, artist)
      .pipe(map((response) => response.data.favorited));
  }

  downloadArtifact(id: number): Observable<Blob> {
    return this.http.get(`${this.baseUrl}/downloads/${id}/artifact`, { responseType: 'blob' });
  }
}

export interface FavoriteArtistPayload {
  provider: string;
  id: string;
  name: string;
  cover_url: string | null;
}
