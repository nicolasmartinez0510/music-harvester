import { DatePipe } from '@angular/common';
import { Component, HostListener, OnInit, inject } from '@angular/core';
import { FormBuilder, ReactiveFormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';

import { ApiService } from '../../core/api.service';
import { ArtistFavoritesService } from '../../core/artist-favorites.service';
import {
  CATALOG_TYPE_LABELS,
  CatalogHit,
  CatalogType,
  PROVIDER_LABELS,
  ProviderInfo,
} from '../../core/models';
import { SearchHistoryEntry, SearchHistoryService } from '../../core/search-history.service';
import { CatalogDownloadButtonComponent } from '../../shared/catalog-download-button.component';
import { CatalogPreviewButtonComponent } from '../../shared/catalog-preview-button.component';
import { IconComponent } from '../../shared/icon.component';
import { PageLoadingComponent } from '../../shared/page-loading.component';
import { PaginationComponent } from '../../shared/pagination.component';

@Component({
  selector: 'app-browse',
  imports: [
    DatePipe,
    ReactiveFormsModule,
    RouterLink,
    IconComponent,
    PaginationComponent,
    CatalogDownloadButtonComponent,
    CatalogPreviewButtonComponent,
    PageLoadingComponent,
  ],
  templateUrl: './browse.component.html',
  styleUrl: './browse.component.css',
})
export class BrowseComponent implements OnInit {
  private readonly api = inject(ApiService);
  private readonly history = inject(SearchHistoryService);
  private readonly favorites = inject(ArtistFavoritesService);
  private readonly fb = inject(FormBuilder);

  readonly typeLabels = CATALOG_TYPE_LABELS;
  readonly providerLabels = PROVIDER_LABELS;
  readonly types: CatalogType[] = ['all', 'track', 'album', 'artist', 'playlist'];
  readonly historyPageSize = 10;

  catalogProviders: ProviderInfo[] = [];
  hits: CatalogHit[] = [];
  loading = false;
  searched = false;
  errorMessage: string | null = null;
  showHistoryModal = false;
  historyPage = 1;

  readonly form = this.fb.nonNullable.group({
    provider: ['deezer'],
    q: [''],
    type: ['all' as CatalogType],
  });

  ngOnInit(): void {
    this.api.getProviders().subscribe({
      next: (providers) => {
        this.catalogProviders = providers.filter((p) => p.has_catalog);
        if (this.catalogProviders.length > 0) {
          const current = this.form.controls.provider.value;
          if (!this.catalogProviders.some((p) => p.name === current)) {
            this.form.controls.provider.setValue(this.catalogProviders[0].name);
          }
        }
      },
    });
  }

  get recentHistory(): SearchHistoryEntry[] {
    return this.history.recent();
  }

  get allHistory(): SearchHistoryEntry[] {
    return this.history.entries();
  }

  get pagedHistory(): SearchHistoryEntry[] {
    const start = (this.historyPage - 1) * this.historyPageSize;
    return this.allHistory.slice(start, start + this.historyPageSize);
  }

  @HostListener('document:keydown.escape')
  onEscape(): void {
    if (this.showHistoryModal) {
      this.closeHistory();
    }
  }

  search(): void {
    const { provider, q, type } = this.form.getRawValue();
    const query = q.trim();
    if (!query) {
      this.errorMessage = 'Escribí un término de búsqueda.';
      return;
    }
    if (!provider) {
      this.errorMessage = 'No hay providers con catálogo disponibles.';
      return;
    }

    this.history.push({ q: query, provider, type });
    this.runSearch(provider, query, type);
  }

  applyHistory(entry: SearchHistoryEntry): void {
    this.form.patchValue({
      provider: entry.provider,
      q: entry.q,
      type: entry.type,
    });
    this.closeHistory();
    this.history.push({ q: entry.q, provider: entry.provider, type: entry.type });
    this.runSearch(entry.provider, entry.q, entry.type);
  }

  openHistory(): void {
    this.historyPage = 1;
    this.showHistoryModal = true;
  }

  closeHistory(): void {
    this.showHistoryModal = false;
  }

  removeHistory(entry: SearchHistoryEntry): void {
    this.history.remove(entry.at);
    const totalPages = Math.max(1, Math.ceil(this.allHistory.length / this.historyPageSize));
    if (this.historyPage > totalPages) {
      this.historyPage = totalPages;
    }
  }

  clearHistory(): void {
    this.history.clear();
    this.historyPage = 1;
  }

  onHistoryPageChange(page: number): void {
    this.historyPage = page;
  }

  setType(type: CatalogType): void {
    this.form.controls.type.setValue(type);
    if (this.searched && this.form.controls.q.value.trim()) {
      this.search();
    }
  }

  detailLink(hit: CatalogHit): string[] | null {
    const provider = this.form.controls.provider.value;
    if (hit.type === 'artist') {
      return ['/browse', provider, 'artists', hit.id];
    }
    if (hit.type === 'album') {
      return ['/browse', provider, 'albums', hit.id];
    }
    if (hit.type === 'playlist') {
      return ['/browse', provider, 'playlists', hit.id];
    }
    return null;
  }

  isFavorite(hit: CatalogHit): boolean {
    void this.favorites.favorites();
    return this.favorites.has(this.form.controls.provider.value, hit.id);
  }

  toggleFavorite(hit: CatalogHit): void {
    this.favorites.toggle({
      provider: this.form.controls.provider.value,
      id: hit.id,
      name: hit.title,
      cover_url: hit.cover_url,
    });
  }

  providerLabel(name: string): string {
    return this.providerLabels[name] ?? name;
  }

  private runSearch(provider: string, query: string, type: CatalogType): void {
    this.loading = true;
    this.errorMessage = null;
    this.searched = true;

    this.api.searchCatalog({ provider, q: query, type, limit: 40 }).subscribe({
      next: (hits) => {
        this.hits = hits;
        this.loading = false;
      },
      error: (error: { error?: { message?: string } }) => {
        this.loading = false;
        this.hits = [];
        this.errorMessage = error.error?.message ?? 'No se pudo buscar en el catálogo.';
      },
    });
  }
}
