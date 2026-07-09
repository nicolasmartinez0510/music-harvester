import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';

import {
  ApiResource,
  AudioFormat,
  DownloadJob,
  Settings,
} from './models';

export interface CreateDownloadPayload {
  url: string;
  format?: AudioFormat;
}

export interface UpdateSettingsPayload {
  music_path?: string;
  default_format?: AudioFormat;
  max_concurrency?: number;
  cookies_path?: string | null;
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
}
