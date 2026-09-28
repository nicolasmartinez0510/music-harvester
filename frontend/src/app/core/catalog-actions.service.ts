import { Injectable, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { EMPTY, Observable, Subscription, interval, startWith, switchMap, tap } from 'rxjs';

import { ToastService } from '../shared/toast.service';
import { ApiService } from './api.service';
import { AudioFormat, DownloadJob, DownloadStatus, SavedPlaylist } from './models';

export type CatalogDownloadUiState = 'idle' | 'queued' | 'done' | 'failed';

const DONE_STORAGE_KEY = 'mh-catalog-downloaded-urls';
const POLL_MS = 3000;

@Injectable({ providedIn: 'root' })
export class CatalogActionsService {
  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastService);
  private readonly router = inject(Router);

  private readonly statesSignal = signal<Record<string, CatalogDownloadUiState>>(this.loadDoneMap());
  private pollSub: Subscription | null = null;
  private hydrated = false;

  readonly states = this.statesSignal.asReadonly();

  constructor() {
    this.hydrateFromJobs();
  }

  status(url: string | null | undefined): CatalogDownloadUiState {
    if (!url) {
      return 'idle';
    }
    return this.statesSignal()[url] ?? 'idle';
  }

  isBusy(url: string | null | undefined): boolean {
    return this.status(url) === 'queued';
  }

  isDone(url: string | null | undefined): boolean {
    return this.status(url) === 'done';
  }

  downloadNow(url: string | null | undefined, format?: AudioFormat): void {
    if (!url) {
      this.toast.error('URL canónica no disponible.');
      return;
    }

    if (this.isDone(url)) {
      this.toast.success('Ya está descargado.');
      return;
    }

    if (this.isBusy(url)) {
      return;
    }

    this.patchState(url, 'queued');

    this.api.createDownload({ url, format }).subscribe({
      next: (job) => {
        this.applyJob(job);
        this.toast.success('Descarga encolada.');
        this.ensurePolling();
      },
      error: (error: { error?: { message?: string } }) => {
        this.patchState(url, 'failed');
        this.toast.error(error.error?.message ?? 'No se pudo encolar la descarga.');
      },
    });
  }

  starPlaylist(url: string | null | undefined): Observable<SavedPlaylist> {
    if (!url) {
      this.toast.error('URL canónica no disponible.');
      return EMPTY;
    }

    return this.api
      .createPlaylist({
        url,
        sync_now: true,
        sync_enabled: true,
      })
      .pipe(
        tap({
          next: (playlist) => {
            this.toast.success('Playlist destacada. Sync en curso.');
            void this.router.navigate(['/playlists', playlist.id]);
          },
          error: (error: { error?: { message?: string } }) => {
            this.toast.error(error.error?.message ?? 'No se pudo destacar la playlist.');
          },
        }),
      );
  }

  private hydrateFromJobs(): void {
    if (this.hydrated) {
      return;
    }
    this.hydrated = true;

    this.api.listDownloads(100).subscribe({
      next: (jobs) => {
        this.applyJobs(jobs);
        if (jobs.some((job) => this.isActiveStatus(job.status))) {
          this.ensurePolling();
        }
      },
    });
  }

  private ensurePolling(): void {
    if (this.pollSub) {
      return;
    }

    this.pollSub = interval(POLL_MS)
      .pipe(
        startWith(0),
        switchMap(() => this.api.listDownloads(100)),
      )
      .subscribe({
        next: (jobs) => {
          this.applyJobs(jobs);
          const hasActive = jobs.some((job) => this.isActiveStatus(job.status));
          const hasLocalQueued = Object.values(this.statesSignal()).includes('queued');
          if (!hasActive && !hasLocalQueued) {
            this.pollSub?.unsubscribe();
            this.pollSub = null;
          }
        },
        error: () => {
          this.pollSub?.unsubscribe();
          this.pollSub = null;
        },
      });
  }

  private applyJobs(jobs: DownloadJob[]): void {
    const next = { ...this.statesSignal() };

    for (const job of jobs) {
      const mapped = this.mapJobStatus(job.status);
      if (!mapped) {
        continue;
      }

      const current = next[job.url];
      if (mapped === 'done' || current !== 'done') {
        next[job.url] = mapped;
      }
    }

    this.statesSignal.set(next);
    this.persistDone(next);
  }

  private applyJob(job: DownloadJob): void {
    const mapped = this.mapJobStatus(job.status);
    if (mapped) {
      this.patchState(job.url, mapped);
    }
  }

  private mapJobStatus(status: DownloadStatus): CatalogDownloadUiState | null {
    if (status === 'done') {
      return 'done';
    }
    if (status === 'pending' || status === 'running') {
      return 'queued';
    }
    if (status === 'failed') {
      return 'failed';
    }
    return null;
  }

  private isActiveStatus(status: DownloadStatus): boolean {
    return status === 'pending' || status === 'running';
  }

  private patchState(url: string, state: CatalogDownloadUiState): void {
    this.statesSignal.update((current) => {
      const next = { ...current, [url]: state };
      this.persistDone(next);
      return next;
    });
  }

  private loadDoneMap(): Record<string, CatalogDownloadUiState> {
    try {
      const raw = localStorage.getItem(DONE_STORAGE_KEY);
      if (!raw) {
        return {};
      }
      const urls = JSON.parse(raw) as string[];
      if (!Array.isArray(urls)) {
        return {};
      }
      return Object.fromEntries(
        urls.filter((url) => typeof url === 'string' && url.length > 0).map((url) => [url, 'done' as const]),
      );
    } catch {
      return {};
    }
  }

  private persistDone(states: Record<string, CatalogDownloadUiState>): void {
    const doneUrls = Object.entries(states)
      .filter(([, state]) => state === 'done')
      .map(([url]) => url)
      .slice(0, 200);
    localStorage.setItem(DONE_STORAGE_KEY, JSON.stringify(doneUrls));
  }
}
