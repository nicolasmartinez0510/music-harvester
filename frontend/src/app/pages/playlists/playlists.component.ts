import { DatePipe } from '@angular/common';
import { Component, DestroyRef, HostListener, inject, OnInit } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { interval, startWith, switchMap } from 'rxjs';

import { ApiService } from '../../core/api.service';
import {
  ApiValidationError,
  PLAYLIST_SYNC_LABELS,
  PROVIDER_LABELS,
  SavedPlaylist,
  detectProviderFromUrl,
} from '../../core/models';
import { IconComponent } from '../../shared/icon.component';
import { PaginationComponent } from '../../shared/pagination.component';
import { ToastService } from '../../shared/toast.service';

@Component({
  selector: 'app-playlists',
  imports: [DatePipe, ReactiveFormsModule, RouterLink, IconComponent, PaginationComponent],
  templateUrl: './playlists.component.html',
  styleUrl: './playlists.component.css',
})
export class PlaylistsComponent implements OnInit {
  private readonly api = inject(ApiService);
  private readonly destroyRef = inject(DestroyRef);
  private readonly fb = inject(FormBuilder);
  private readonly toast = inject(ToastService);

  readonly syncLabels = PLAYLIST_SYNC_LABELS;
  readonly providerLabels = PROVIDER_LABELS;

  playlists: SavedPlaylist[] = [];
  loading = true;
  errorMessage: string | null = null;
  formError: string | null = null;
  showForm = false;
  showDeleteConfirm = false;
  playlistPendingDelete: SavedPlaylist | null = null;
  submitting = false;
  syncingId: number | null = null;
  deletingId: number | null = null;

  page = 1;
  pageSize = 10;

  readonly form = this.fb.nonNullable.group({
    url: ['', [Validators.required, Validators.maxLength(2048)]],
    sync_now: [true],
  });

  ngOnInit(): void {
    interval(8000)
      .pipe(
        startWith(0),
        switchMap(() => this.api.listPlaylists()),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe({
        next: (playlists) => {
          this.playlists = playlists;
          this.loading = false;
          this.errorMessage = null;
          this.clampPage();
        },
        error: () => {
          this.loading = false;
          this.errorMessage = 'No se pudieron cargar las playlists.';
        },
      });
  }

  get pagedPlaylists(): SavedPlaylist[] {
    const start = (this.page - 1) * this.pageSize;
    return this.playlists.slice(start, start + this.pageSize);
  }

  @HostListener('document:keydown.escape')
  onEscape(): void {
    if (this.showDeleteConfirm && this.deletingId === null) {
      this.cancelDelete();
      return;
    }
    if (this.showForm && !this.submitting) {
      this.cancelForm();
    }
  }

  openForm(): void {
    this.showForm = true;
    this.formError = null;
    this.errorMessage = null;
    this.form.reset({ url: '', sync_now: true });
  }

  cancelForm(): void {
    this.showForm = false;
    this.formError = null;
    this.form.reset({ url: '', sync_now: true });
  }

  submit(): void {
    this.formError = null;

    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }

    this.submitting = true;
    const { url, sync_now } = this.form.getRawValue();

    this.api.createPlaylist({ url, sync_now }).subscribe({
      next: (playlist) => {
        this.submitting = false;
        this.showForm = false;
        this.form.reset({ url: '', sync_now: true });
        this.playlists = [playlist, ...this.playlists.filter((p) => p.id !== playlist.id)];
        this.page = 1;
        this.toast.success('Playlist guardada.');
      },
      error: (error: { error?: ApiValidationError }) => {
        this.submitting = false;
        const body = error.error;
        this.formError =
          body?.message ??
          body?.errors?.['url']?.[0] ??
          'No se pudo guardar la playlist.';
      },
    });
  }

  sync(playlist: SavedPlaylist): void {
    if (this.syncingId === playlist.id || playlist.last_sync_status === 'running') {
      return;
    }

    this.syncingId = playlist.id;
    this.api.syncPlaylist(playlist.id).subscribe({
      next: (updated) => {
        this.syncingId = null;
        this.playlists = this.playlists.map((item) =>
          item.id === updated.id
            ? { ...item, ...updated, last_sync_status: 'running' }
            : item,
        );
        this.toast.success('Sync encolado.');
      },
      error: (error: { error?: ApiValidationError }) => {
        this.syncingId = null;
        const message =
          error.error?.message ?? `No se pudo sincronizar la playlist #${playlist.id}.`;
        this.errorMessage = message;
        this.toast.error(message);
      },
    });
  }

  remove(playlist: SavedPlaylist): void {
    this.playlistPendingDelete = playlist;
    this.showDeleteConfirm = true;
  }

  cancelDelete(): void {
    if (this.deletingId !== null) {
      return;
    }
    this.showDeleteConfirm = false;
    this.playlistPendingDelete = null;
  }

  confirmDelete(): void {
    const playlist = this.playlistPendingDelete;
    if (!playlist || this.deletingId !== null) {
      return;
    }

    this.deletingId = playlist.id;
    this.api.deletePlaylist(playlist.id).subscribe({
      next: () => {
        this.deletingId = null;
        this.showDeleteConfirm = false;
        this.playlistPendingDelete = null;
        this.playlists = this.playlists.filter((item) => item.id !== playlist.id);
        this.clampPage();
        this.toast.success('Playlist eliminada.');
      },
      error: () => {
        this.deletingId = null;
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

  providerLabel(provider: string): string {
    return this.providerLabels[provider] ?? provider;
  }

  detectedHint(): string {
    const url = this.form.controls.url.value;
    const detected = detectProviderFromUrl(url);
    if (!detected) {
      return '';
    }
    return this.providerLabel(detected);
  }

  syncClass(status: SavedPlaylist['last_sync_status']): string {
    return `status status-${status === 'idle' ? 'pending' : status === 'done' ? 'done' : status === 'running' ? 'running' : 'failed'}`;
  }

  private clampPage(): void {
    const totalPages = Math.max(1, Math.ceil(this.playlists.length / this.pageSize));
    if (this.page > totalPages) {
      this.page = totalPages;
    }
  }
}
