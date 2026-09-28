import { DecimalPipe } from '@angular/common';
import { Component, DestroyRef, OnInit, inject } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { EMPTY, catchError, combineLatest, of, switchMap } from 'rxjs';

import { ApiService } from '../../core/api.service';
import { ArtistFavoritesService } from '../../core/artist-favorites.service';
import { CatalogArtist, CatalogHit } from '../../core/models';
import { CatalogDownloadButtonComponent } from '../../shared/catalog-download-button.component';
import { IconComponent } from '../../shared/icon.component';
import { PageLoadingComponent } from '../../shared/page-loading.component';
import { PaginationComponent } from '../../shared/pagination.component';

type ArtistTab = 'tracks' | 'albums';
type AlbumSort = 'title' | 'release_date' | 'popularity';

@Component({
  selector: 'app-browse-artist',
  imports: [DecimalPipe, RouterLink, IconComponent, PaginationComponent, CatalogDownloadButtonComponent, PageLoadingComponent],
  templateUrl: './browse-artist.component.html',
  styleUrl: './browse-detail.css',
})
export class BrowseArtistComponent implements OnInit {
  private readonly api = inject(ApiService);
  private readonly favorites = inject(ArtistFavoritesService);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly destroyRef = inject(DestroyRef);

  readonly trackPageSize = 20;
  readonly albumPageSize = 15;
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
  trackPage = 1;
  albumPage = 1;
  albumSort: AlbumSort = 'release_date';
  showBackToBrowse = true;

  ngOnInit(): void {
    // Same route template for every artist; subscribe so favorites sidebar switches reload.
    combineLatest([this.route.paramMap, this.route.queryParamMap])
      .pipe(
        switchMap(([params, query]) => {
          this.provider = params.get('provider') ?? '';
          const id = params.get('id') ?? '';
          this.showBackToBrowse = query.get('from') !== 'favorites';

          if (!this.provider || !id) {
            void this.router.navigate(['/browse']);
            return EMPTY;
          }

          this.loading = true;
          this.errorMessage = null;
          this.artist = null;
          this.activeTab = 'tracks';
          this.trackPage = 1;
          this.albumPage = 1;
          this.albumSort = 'release_date';

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

  get pagedAlbums(): CatalogHit[] {
    const albums = this.sortedAlbums;
    const start = (this.albumPage - 1) * this.albumPageSize;
    return albums.slice(start, start + this.albumPageSize);
  }

  setTab(tab: ArtistTab): void {
    this.activeTab = tab;
  }

  setAlbumSort(sort: AlbumSort): void {
    if (this.albumSort === sort) {
      return;
    }
    this.albumSort = sort;
    this.albumPage = 1;
  }

  onTrackPageChange(page: number): void {
    this.trackPage = page;
  }

  onAlbumPageChange(page: number): void {
    this.albumPage = page;
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

  albumYear(hit: CatalogHit): string | null {
    const date = hit.release_date;
    if (!date || date.length < 4) {
      return null;
    }
    return date.slice(0, 4);
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
