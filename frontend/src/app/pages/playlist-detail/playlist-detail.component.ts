import { DatePipe } from '@angular/common';
import { Component, DestroyRef, HostListener, inject, OnInit } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { interval, startWith, switchMap } from 'rxjs';

import { ApiService } from '../../core/api.service';
import {
  ApiValidationError,
  AUDIO_FORMATS,
  AudioFormat,
  PLAYLIST_SYNC_LABELS,
  PLAYLIST_TRACK_LABELS,
  PROVIDER_LABELS,
  SavedPlaylistDetail,
  SavedPlaylistTrack,
} from '../../core/models';
import { IconComponent } from '../../shared/icon.component';
import { PageLoadingComponent } from '../../shared/page-loading.component';
import { PaginationComponent } from '../../shared/pagination.component';
import { ToastService } from '../../shared/toast.service';

@Component({
  selector: 'app-playlist-detail',
  imports: [DatePipe, ReactiveFormsModule, RouterLink, IconComponent, PaginationComponent, PageLoadingComponent],
  templateUrl: './playlist-detail.component.html',
  styleUrl: './playlist-detail.component.css',
})
export class PlaylistDetailComponent implements OnInit {
  private readonly api = inject(ApiService);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly destroyRef = inject(DestroyRef);
  private readonly fb = inject(FormBuilder);
  private readonly toast = inject(ToastService);

  readonly syncLabels = PLAYLIST_SYNC_LABELS;
  readonly trackLabels = PLAYLIST_TRACK_LABELS;
  readonly providerLabels = PROVIDER_LABELS;
  readonly formats = AUDIO_FORMATS;

  playlistId = 0;
  detail: SavedPlaylistDetail | null = null;
  loading = true;
  saving = false;
  syncing = false;
  showConfigModal = false;
  showDeleteConfirm = false;
  deleting = false;
  errorMessage: string | null = null;

  page = 1;
  pageSize = 10;

  readonly form = this.fb.nonNullable.group({
    sync_enabled: [true],
    sync_interval_minutes: [5, [Validators.required, Validators.min(1), Validators.max(10080)]],
    default_format: ['' as string],
  });

  ngOnInit(): void {
    this.playlistId = Number(this.route.snapshot.paramMap.get('id'));

    if (!Number.isFinite(this.playlistId) || this.playlistId <= 0) {
      void this.router.navigate(['/playlists']);
      return;
    }

    interval(8000)
      .pipe(
        startWith(0),
        switchMap(() => this.api.getPlaylist(this.playlistId)),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe({
        next: (detail) => {
          this.detail = detail;
          this.loading = false;
          this.errorMessage = null;
          if (!this.showConfigModal || !this.form.dirty) {
            this.form.patchValue({
              sync_enabled: detail.sync_enabled,
              sync_interval_minutes: detail.sync_interval_minutes,
              default_format: detail.default_format ?? '',
            });
            this.form.markAsPristine();
          }
          this.clampPage();
        },
        error: () => {
          this.loading = false;
          this.errorMessage = 'No se pudo cargar la playlist.';
        },
      });
  }

  get pagedTracks(): SavedPlaylistTrack[] {
    const tracks = this.detail?.tracks ?? [];
    const start = (this.page - 1) * this.pageSize;
    return tracks.slice(start, start + this.pageSize);
  }

  @HostListener('document:keydown.escape')
  onEscape(): void {
    if (this.showDeleteConfirm && !this.deleting) {
      this.cancelDelete();
      return;
    }
    if (this.showConfigModal) {
      this.closeConfigModal();
    }
  }

  providerLabel(provider: string): string {
    return this.providerLabels[provider] ?? provider;
  }

  syncClass(status: string): string {
    return `status status-${status === 'idle' ? 'pending' : status === 'done' ? 'done' : status === 'running' ? 'running' : 'failed'}`;
  }

  openConfigModal(): void {
    if (!this.detail) {
      return;
    }

    this.form.patchValue({
      sync_enabled: this.detail.sync_enabled,
      sync_interval_minutes: this.detail.sync_interval_minutes,
      default_format: this.detail.default_format ?? '',
    });
    this.form.markAsPristine();
    this.showConfigModal = true;
  }

  closeConfigModal(): void {
    this.showConfigModal = false;
  }

  saveSettings(): void {
    this.errorMessage = null;

    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }

    const { sync_enabled, sync_interval_minutes, default_format } = this.form.getRawValue();
    this.saving = true;

    this.api
      .updatePlaylist(this.playlistId, {
        sync_enabled,
        sync_interval_minutes,
        default_format: default_format === '' ? null : (default_format as AudioFormat),
      })
      .subscribe({
        next: (updated) => {
          this.saving = false;
          this.form.markAsPristine();
          if (this.detail) {
            this.detail = { ...this.detail, ...updated };
          }
          this.showConfigModal = false;
          this.toast.success('Configuración guardada.');
        },
        error: (error: { error?: ApiValidationError }) => {
          this.saving = false;
          const message = error.error?.message ?? 'No se pudo guardar.';
          this.errorMessage = message;
          this.toast.error(message);
        },
      });
  }

  syncNow(): void {
    if (this.syncing || this.detail?.last_sync_status === 'running') {
      return;
    }

    this.syncing = true;
    this.errorMessage = null;
    this.api.syncPlaylist(this.playlistId).subscribe({
      next: (updated) => {
        this.syncing = false;
        if (this.detail) {
          this.detail = { ...this.detail, ...updated, last_sync_status: 'running' };
        }
        this.toast.success('Sync encolado.');
      },
      error: (error: { error?: ApiValidationError }) => {
        this.syncing = false;
        const message = error.error?.message ?? 'No se pudo encolar el sync.';
        this.errorMessage = message;
        this.toast.error(message);
      },
    });
  }

  remove(): void {
    this.showDeleteConfirm = true;
  }

  cancelDelete(): void {
    if (this.deleting) {
      return;
    }
    this.showDeleteConfirm = false;
  }

  confirmDelete(): void {
    if (this.deleting) {
      return;
    }

    this.deleting = true;
    this.api.deletePlaylist(this.playlistId).subscribe({
      next: () => {
        this.deleting = false;
        this.showDeleteConfirm = false;
        this.toast.success('Playlist eliminada.');
        void this.router.navigate(['/playlists']);
      },
      error: () => {
        this.deleting = false;
        const message = 'No se pudo eliminar la playlist.';
        this.errorMessage = message;
        this.toast.error(message);
      },
    });
  }

  onPageChange(page: number): void {
    this.page = page;
  }

  onPageSizeChange(pageSize: number): void {
    this.pageSize = pageSize;
    this.page = 1;
  }

  trackStatusClass(status: string): string {
    if (status === 'downloaded') {
      return 'status status-done';
    }
    if (status === 'existing') {
      return 'status status-existing';
    }
    if (status === 'failed') {
      return 'status status-failed';
    }

    return 'status status-pending';
  }

  private clampPage(): void {
    const total = this.detail?.tracks.length ?? 0;
    const totalPages = Math.max(1, Math.ceil(total / this.pageSize));
    if (this.page > totalPages) {
      this.page = totalPages;
    }
  }
}
