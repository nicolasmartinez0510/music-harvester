import { DecimalPipe } from '@angular/common';
import { Component, DestroyRef, OnInit, inject } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { ActivatedRoute, ParamMap, Router, RouterLink } from '@angular/router';
import { EMPTY, catchError, of, switchMap } from 'rxjs';

import { ApiService } from '../../core/api.service';
import { ArtistFavoritesService } from '../../core/artist-favorites.service';
import { ArtistPageBackdropService } from '../../core/artist-page-backdrop.service';
import { CatalogArtist, CatalogHit } from '../../core/models';
import { CatalogDownloadButtonComponent } from '../../shared/catalog-download-button.component';
import { IconComponent } from '../../shared/icon.component';
import { PageLoadingComponent } from '../../shared/page-loading.component';
import { PaginationComponent } from '../../shared/pagination.component';

type ArtistTab = 'tracks' | 'albums';
type AlbumSort = 'title' | 'release_date' | 'popularity';
type ReleaseTab = 'albums' | 'singles';

@Component({
  selector: 'app-browse-artist',
  imports: [DecimalPipe, RouterLink, IconComponent, PaginationComponent, CatalogDownloadButtonComponent, PageLoadingComponent],
  templateUrl: './browse-artist.component.html',
  styleUrl: './browse-detail.css',
})
export class BrowseArtistComponent implements OnInit {
  private readonly api = inject(ApiService);
  private readonly favorites = inject(ArtistFavoritesService);
  private readonly backdrop = inject(ArtistPageBackdropService);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly destroyRef = inject(DestroyRef);
  private readonly mobileAlbums = window.matchMedia('(max-width: 640px)');
  private artistKey = '';

  readonly trackPageSize = 20;
  albumPageSize = 12;
  readonly releaseTabs: { id: ReleaseTab; label: string }[] = [
    { id: 'albums', label: 'Álbumes discográficos' },
    { id: 'singles', label: 'Sencillos y EPs' },
  ];
  readonly albumSortOptions: { id: AlbumSort; label: string }[] = [
    { id: 'title', label: 'Alfabético' },
    { id: 'release_date', label: 'Fecha' },
    { id: 'popularity', label: 'Popularidad' },
  ];

  provider = '';
  artist: CatalogArtist | null = null;
  loading = true;
  errorMessage: string | null = null;
  activeTab: ArtistTab = 'tracks';
  releaseTab: ReleaseTab = 'albums';
  trackPage = 1;
  albumPage = 1;
  singlePage = 1;
  albumSort: AlbumSort = 'release_date';
  showBackToBrowse = true;
  showBackToCollection = false;
  collectionBackKind: 'artists' | 'albums' | 'tracks' | 'playlists' = 'artists';

  ngOnInit(): void {
    this.syncAlbumPageSize();
    const onViewportChange = () => this.syncAlbumPageSize();
    this.mobileAlbums.addEventListener('change', onViewportChange);
    this.destroyRef.onDestroy(() => {
      this.mobileAlbums.removeEventListener('change', onViewportChange);
      this.backdrop.clear();
    });

    this.route.queryParamMap.pipe(takeUntilDestroyed(this.destroyRef)).subscribe((query) => {
      this.applyQuery(query);
    });

    // Same route template for every artist; subscribe so favorites sidebar switches reload.
    // View state lives in the query string, so only a new artist refetches.
    this.route.paramMap
      .pipe(
        switchMap((params) => {
          this.provider = params.get('provider') ?? '';
          const id = params.get('id') ?? '';

          if (!this.provider || !id) {
            void this.router.navigate(['/browse']);
            return EMPTY;
          }

          const key = `${this.provider}:${id}`;
          if (key === this.artistKey && this.artist) {
            return EMPTY;
          }

          this.artistKey = key;
          this.loading = true;
          this.errorMessage = null;
          this.artist = null;
          this.backdrop.clear();
          this.applyQuery(this.route.snapshot.queryParamMap);

          return this.api.getCatalogArtist(this.provider, id).pipe(
            catchError((error: { error?: { message?: string } }) => {
              this.loading = false;
              this.errorMessage = error.error?.message ?? 'No se pudo cargar el artista.';
              return of(null);
            }),
          );
        }),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe((artist) => {
        if (artist === null) {
          return;
        }
        this.artist = artist;
        this.loading = false;
        this.backdrop.set(artist.cover_url);
      });
  }

  get isFavorite(): boolean {
    if (!this.artist) {
      return false;
    }
    return this.favorites.has(this.provider, this.artist.id);
  }

  get pagedTracks(): CatalogHit[] {
    const tracks = this.artist?.top_tracks ?? [];
    const start = (this.trackPage - 1) * this.trackPageSize;
    return tracks.slice(start, start + this.trackPageSize);
  }

  get sortedAlbums(): CatalogHit[] {
    const albums = [...(this.artist?.albums ?? [])];
    albums.sort((a, b) => this.compareAlbums(a, b));
    return albums;
  }

  get discographyAlbums(): CatalogHit[] {
    return this.sortedAlbums.filter((hit) => !this.isSingleOrEp(hit));
  }

  get singlesAndEps(): CatalogHit[] {
    return this.sortedAlbums.filter((hit) => this.isSingleOrEp(hit));
  }

  get activeReleaseList(): CatalogHit[] {
    return this.releaseTab === 'singles' ? this.singlesAndEps : this.discographyAlbums;
  }

  get releasePage(): number {
    const page = this.releaseTab === 'singles' ? this.singlePage : this.albumPage;
    const pages = Math.max(1, Math.ceil(this.activeReleaseList.length / this.albumPageSize));
    return Math.min(page, pages);
  }

  get pagedRelease(): CatalogHit[] {
    const start = (this.releasePage - 1) * this.albumPageSize;
    return this.activeReleaseList.slice(start, start + this.albumPageSize);
  }

  get releaseEmptyMessage(): string {
    return this.releaseTab === 'singles' ? 'Sin sencillos ni EPs.' : 'Sin álbumes discográficos.';
  }

  setTab(tab: ArtistTab): void {
    if (this.activeTab === tab) {
      return;
    }
    this.activeTab = tab;
    this.syncViewState();
  }

  setReleaseTab(tab: ReleaseTab): void {
    if (this.releaseTab === tab) {
      return;
    }
    this.releaseTab = tab;
    this.syncViewState();
  }

  setAlbumSort(sort: AlbumSort): void {
    if (this.albumSort === sort) {
      return;
    }
    this.albumSort = sort;
    this.albumPage = 1;
    this.singlePage = 1;
    this.syncViewState();
  }

  onTrackPageChange(page: number): void {
    if (this.trackPage === page) {
      return;
    }
    this.trackPage = page;
    this.syncViewState();
  }

  onReleasePageChange(page: number): void {
    if (this.releasePage === page) {
      return;
    }
    if (this.releaseTab === 'singles') {
      this.singlePage = page;
    } else {
      this.albumPage = page;
    }
    this.syncViewState();
  }

  toggleFavorite(): void {
    if (!this.artist) {
      return;
    }
    this.favorites.toggle({
      provider: this.provider,
      id: this.artist.id,
      name: this.artist.name,
      cover_url: this.artist.cover_url,
    });
  }

  albumLink(hit: CatalogHit): string[] {
    return ['/browse', this.provider, 'albums', hit.id];
  }

  get albumQueryParams(): Record<string, string | number> {
    const params: Record<string, string | number> = {
      from: 'artist',
      artistId: this.artist?.id ?? '',
      tab: this.activeTab,
      release: this.releaseTab,
      trackPage: this.trackPage,
      albumPage: this.albumPage,
      singlePage: this.singlePage,
      albumSort: this.albumSort,
    };
    const origin = this.route.snapshot.queryParamMap.get('from');
    if (origin === 'favorites' || origin === 'collection') {
      params['artistFrom'] = origin;
    }
    return params;
  }

  private isSingleOrEp(hit: CatalogHit): boolean {
    const type = hit.record_type?.toLowerCase();
    return type === 'single' || type === 'ep';
  }

  albumYear(hit: CatalogHit): string | null {
    const date = hit.release_date;
    if (!date || date.length < 4) {
      return null;
    }
    return date.slice(0, 4);
  }

  private applyQuery(query: ParamMap): void {
    const from = query.get('from');
    this.showBackToBrowse = from !== 'favorites' && from !== 'collection';
    this.showBackToCollection = from === 'collection';
    this.collectionBackKind = 'artists';

    const tab = query.get('tab');
    this.activeTab = tab === 'albums' || tab === 'tracks' ? tab : 'tracks';

    const release = query.get('release');
    this.releaseTab = release === 'singles' || release === 'albums' ? release : 'albums';

    const sort = query.get('albumSort');
    this.albumSort =
      sort === 'title' || sort === 'release_date' || sort === 'popularity' ? sort : 'release_date';

    this.trackPage = this.parsePage(query.get('trackPage'));
    this.albumPage = this.parsePage(query.get('albumPage'));
    this.singlePage = this.parsePage(query.get('singlePage'));
  }

  private parsePage(value: string | null): number {
    const page = Number(value);
    return Number.isInteger(page) && page >= 1 ? page : 1;
  }

  private syncViewState(): void {
    void this.router.navigate([], {
      relativeTo: this.route,
      queryParams: {
        tab: this.activeTab,
        release: this.releaseTab,
        trackPage: this.trackPage,
        albumPage: this.albumPage,
        singlePage: this.singlePage,
        albumSort: this.albumSort,
      },
      queryParamsHandling: 'merge',
      replaceUrl: true,
    });
  }

  private syncAlbumPageSize(): void {
    this.albumPageSize = this.mobileAlbums.matches ? 6 : 12;
  }

  private compareAlbums(a: CatalogHit, b: CatalogHit): number {
    switch (this.albumSort) {
      case 'title':
        return a.title.localeCompare(b.title, 'es', { sensitivity: 'base' });
      case 'popularity': {
        const fansA = a.fans ?? -1;
        const fansB = b.fans ?? -1;
        if (fansB !== fansA) {
          return fansB - fansA;
        }
        return a.title.localeCompare(b.title, 'es', { sensitivity: 'base' });
      }
      case 'release_date':
      default: {
        const dateA = a.release_date ?? '';
        const dateB = b.release_date ?? '';
        if (dateA !== dateB) {
          return dateB.localeCompare(dateA);
        }
        return a.title.localeCompare(b.title, 'es', { sensitivity: 'base' });
      }
    }
  }
}
