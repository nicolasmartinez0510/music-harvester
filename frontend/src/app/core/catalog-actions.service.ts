import { Injectable, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { EMPTY, Observable, Subscription, interval, startWith, switchMap, tap } from 'rxjs';

import { ToastService } from '../shared/toast.service';
import { ApiService } from './api.service';
import { AudioFormat, DownloadJob, DownloadStatus, SavedPlaylist } from './models';

export type CatalogDownloadUiState = 'idle' | 'queued' | 'done' | 'failed';

const POLL_MS = 3000;
const LIST_LIMIT = 200;

@Injectable({ providedIn: 'root' })
export class CatalogActionsService {
  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastService);
  private readonly router = inject(Router);

  private readonly statesSignal = signal<Record<string, CatalogDownloadUiState>>({});
  private pollSub: Subscription | null = null;

  readonly states = this.statesSignal.asReadonly();

  constructor() {
    this.reloadFromServer();
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
        this.mergeJob(job);
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

  /** Rebuild download UI state from the server (source of truth). */
  reloadFromServer(): void {
    this.api.listDownloads(LIST_LIMIT).subscribe({
      next: (jobs) => {
        this.replaceFromJobs(jobs);
        if (jobs.some((job) => this.isActiveStatus(job.status))) {
          this.ensurePolling();
        }
      },
      error: () => {
        this.statesSignal.set({});
      },
    });
  }

  /** Apply a fresh downloads list (e.g. from the Descargas page poll). */
  applyJobsSnapshot(jobs: DownloadJob[]): void {
    this.replaceFromJobs(jobs);
  }

  private ensurePolling(): void {
    if (this.pollSub) {
      return;
    }

    this.pollSub = interval(POLL_MS)
      .pipe(
        startWith(0),
        switchMap(() => this.api.listDownloads(LIST_LIMIT)),
      )
      .subscribe({
        next: (jobs) => {
          this.replaceFromJobs(jobs);
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

  private replaceFromJobs(jobs: DownloadJob[]): void {
    const next: Record<string, CatalogDownloadUiState> = {};
    const previous = this.statesSignal();

    for (const job of jobs) {
      const mapped = this.mapJobStatus(job);
      if (!mapped) {
        continue;
      }

      const current = next[job.url];
      if (!current || mapped === 'done' || (mapped === 'queued' && current === 'failed')) {
        next[job.url] = mapped;
      }
    }

    // Keep optimistic local "queued" until the server lists the job.
    for (const [url, state] of Object.entries(previous)) {
      if (state === 'queued' && next[url] === undefined) {
        next[url] = 'queued';
      }
    }

    this.statesSignal.set(next);
  }

  private mergeJob(job: DownloadJob): void {
    const mapped = this.mapJobStatus(job);
    if (!mapped) {
      return;
    }
    this.patchState(job.url, mapped);
  }

  private mapJobStatus(job: DownloadJob): CatalogDownloadUiState | null {
    if (job.status === 'done') {
      return job.files_present ? 'done' : null;
    }
    if (job.status === 'pending' || job.status === 'running') {
      return 'queued';
    }
    if (job.status === 'failed') {
      return 'failed';
    }
    return null;
  }

  private isActiveStatus(status: DownloadStatus): boolean {
    return status === 'pending' || status === 'running';
  }

  private patchState(url: string, state: CatalogDownloadUiState): void {
    this.statesSignal.update((current) => ({ ...current, [url]: state }));
  }
}
