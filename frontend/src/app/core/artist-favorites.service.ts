import { Injectable, computed, inject, signal } from '@angular/core';

import { ApiService } from './api.service';

export interface FavoriteArtist {
  provider: string;
  id: string;
  name: string;
  cover_url: string | null;
}

@Injectable({ providedIn: 'root' })
export class ArtistFavoritesService {
  private readonly api = inject(ApiService);
  private readonly favoritesSignal = signal<FavoriteArtist[]>([]);

  readonly favorites = this.favoritesSignal.asReadonly();
  readonly count = computed(() => this.favoritesSignal().length);

  load(): void {
    this.api.listFavoriteArtists().subscribe({
      next: (items) => this.favoritesSignal.set(items),
      error: () => this.favoritesSignal.set([]),
    });
  }

  list(): FavoriteArtist[] {
    return this.favoritesSignal();
  }

  has(provider: string, id: string): boolean {
    return this.favoritesSignal().some((item) => item.provider === provider && item.id === id);
  }

  toggle(artist: FavoriteArtist): boolean {
    const exists = this.has(artist.provider, artist.id);
    if (exists) {
      this.favoritesSignal.update((list) =>
        list.filter((item) => !(item.provider === artist.provider && item.id === artist.id)),
      );
    } else {
      this.favoritesSignal.update((list) => [artist, ...list]);
    }

    this.api.toggleFavoriteArtist(artist).subscribe({
      error: () => this.load(),
    });

    return !exists;
  }

  clear(): void {
    this.favoritesSignal.set([]);
  }
}
