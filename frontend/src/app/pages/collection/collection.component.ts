import { Component, OnInit, inject, signal } from '@angular/core';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { EMPTY, catchError, forkJoin, of, switchMap } from 'rxjs';

import { ApiService } from '../../core/api.service';
import { CatalogActionsService } from '../../core/catalog-actions.service';
import {
  CatalogHit,
  LIBRARY_KIND_LABELS,
  LibraryKind,
  PROVIDER_LABELS,
  SavedPlaylist,
} from '../../core/models';
import { CatalogDownloadButtonComponent } from '../../shared/catalog-download-button.component';
import { IconComponent, IconName } from '../../shared/icon.component';
import { PageLoadingComponent } from '../../shared/page-loading.component';

type SyncUiState = 'idle' | 'syncing' | 'done' | 'failed';

@Component({
  selector: 'app-collection',
  imports: [RouterLink, CatalogDownloadButtonComponent, IconComponent, PageLoadingComponent],
  templateUrl: './collection.component.html',
  styleUrl: './collection.component.css',
})
export class CollectionComponent implements OnInit {
  private readonly api = inject(ApiService);
  private readonly actions = inject(CatalogActionsService);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);

  readonly kindLabels = LIBRARY_KIND_LABELS;
  readonly providerLabels = PROVIDER_LABELS;

  provider = '';
  kind: LibraryKind = 'artists';
  hits: CatalogHit[] = [];
  loading = true;
  errorMessage: string | null = null;

  /** URL → saved playlist snapshot for sync button state. */
  private readonly savedByUrl = signal<Record<string, SavedPlaylist>>({});
  private readonly pendingSyncUrls = signal<ReadonlySet<string>>(new Set());

  ngOnInit(): void {
    this.route.paramMap
      .pipe(
        switchMap((params) => {
          this.provider = params.get('provider') ?? '';
          const kind = params.get('kind') ?? '';
          if (!this.provider || !this.isKind(kind)) {
            void this.router.navigate(['/downloads']);
            return EMPTY;
          }

          this.kind = kind;
          this.loading = true;
          this.errorMessage = null;
          this.hits = [];

          const library$ = this.api.getLibrary(this.provider, kind, { limit: 100 }).pipe(
            catchError((error: { error?: { message?: string } }) => {
              this.errorMessage =
                error.error?.message ?? 'No se pudo cargar la colección.';
              return of([] as CatalogHit[]);
            }),
          );

          const playlists$ =
            kind === 'playlists'
              ? this.api.listPlaylists().pipe(catchError(() => of([] as SavedPlaylist[])))
              : of([] as SavedPlaylist[]);

          return forkJoin({ hits: library$, playlists: playlists$ });
        }),
      )
      .subscribe(({ hits, playlists }) => {
        this.hits = hits;
        this.indexSavedPlaylists(playlists);
        this.loading = false;
      });
  }

  providerLabel(name: string): string {
    return this.providerLabels[name] ?? name;
  }

  detailLink(hit: CatalogHit): string[] | null {
    if (hit.type === 'artist') {
      return ['/browse', this.provider, 'artists', hit.id];
    }
    if (hit.type === 'album') {
      return ['/browse', this.provider, 'albums', hit.id];
    }
    if (hit.type === 'playlist') {
      return ['/browse', this.provider, 'playlists', hit.id];
    }
    return null;
  }

  syncState(hit: CatalogHit): SyncUiState {
    const url = hit.canonical_url;
    if (!url) {
      return 'idle';
    }
    if (this.pendingSyncUrls().has(url)) {
      return 'syncing';
    }
    const saved = this.savedFor(url);
    if (!saved) {
      return 'idle';
    }
    if (saved.last_sync_status === 'running') {
      return 'syncing';
    }
    if (saved.last_sync_status === 'done') {
      return 'done';
    }
    if (saved.last_sync_status === 'failed') {
      return 'failed';
    }
    return 'idle';
  }

  syncLabel(hit: CatalogHit): string {
    const state = this.syncState(hit);
    if (state === 'syncing') {
      return 'Sincronizando…';
    }
    if (state === 'done') {
      return 'Sincronizada';
    }
    return 'Sincronizar';
  }

  syncIcon(hit: CatalogHit): IconName {
    const state = this.syncState(hit);
    if (state === 'syncing') {
      return 'spinner';
    }
    if (state === 'done') {
      return 'check';
    }
    return 'refresh';
  }

  syncPlaylist(hit: CatalogHit): void {
    const url = hit.canonical_url;
    if (!url || this.syncState(hit) === 'syncing' || this.syncState(hit) === 'done') {
      return;
    }

    this.pendingSyncUrls.update((current) => new Set([...current, url]));
    this.actions.starPlaylist(url).subscribe({
      next: (playlist) => {
        this.savedByUrl.update((current) => ({
          ...current,
          [url]: { ...playlist, last_sync_status: 'running' },
        }));
        this.clearPending(url);
      },
      error: () => this.clearPending(url),
      complete: () => this.clearPending(url),
    });
  }

  private clearPending(url: string): void {
    this.pendingSyncUrls.update((current) => {
      const next = new Set(current);
      next.delete(url);
      return next;
    });
  }

  private indexSavedPlaylists(playlists: SavedPlaylist[]): void {
    const map: Record<string, SavedPlaylist> = {};
    for (const playlist of playlists) {
      if (!playlist.url) {
        continue;
      }
      map[playlist.url] = playlist;
      const key = this.playlistKey(playlist.url);
      if (key) {
        map[key] = playlist;
      }
    }
    this.savedByUrl.set(map);
  }

  private savedFor(url: string | null | undefined): SavedPlaylist | undefined {
    if (!url) {
      return undefined;
    }
    return this.savedByUrl()[url] ?? this.savedByUrl()[this.playlistKey(url) ?? ''];
  }

  private playlistKey(url: string): string | null {
    const match = url.match(/deezer\.com(?:\/[a-z]{2})?\/playlist\/(\d+)/i);
    if (match) {
      return `deezer:playlist:${match[1]}`;
    }
    const yt = url.match(/[?&]list=([a-zA-Z0-9_-]+)/);
    if (yt) {
      return `youtube:playlist:${yt[1]}`;
    }
    return null;
  }

  private isKind(value: string): value is LibraryKind {
    return value === 'artists' || value === 'albums' || value === 'tracks' || value === 'playlists';
  }
}
