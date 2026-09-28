import { Injectable, computed, signal } from '@angular/core';

export interface FavoriteArtist {
  provider: string;
  id: string;
  name: string;
  cover_url: string | null;
}

const STORAGE_KEY = 'mh-artist-favorites';

@Injectable({ providedIn: 'root' })
export class ArtistFavoritesService {
  private readonly favoritesSignal = signal<FavoriteArtist[]>(this.load());

  readonly favorites = this.favoritesSignal.asReadonly();
  readonly count = computed(() => this.favoritesSignal().length);

  list(): FavoriteArtist[] {
    return this.favoritesSignal();
  }

  has(provider: string, id: string): boolean {
    return this.favoritesSignal().some((f) => f.provider === provider && f.id === id);
  }

  toggle(artist: FavoriteArtist): boolean {
    const exists = this.has(artist.provider, artist.id);
    if (exists) {
      this.favoritesSignal.update((list) =>
        list.filter((f) => !(f.provider === artist.provider && f.id === artist.id)),
      );
    } else {
      this.favoritesSignal.update((list) => [
        { ...artist },
        ...list.filter((f) => !(f.provider === artist.provider && f.id === artist.id)),
      ]);
    }
    this.persist();
    return !exists;
  }

  private load(): FavoriteArtist[] {
    try {
      const raw = localStorage.getItem(STORAGE_KEY);
      if (!raw) {
        return [];
      }
      const parsed = JSON.parse(raw) as FavoriteArtist[];
      if (!Array.isArray(parsed)) {
        return [];
      }
      return parsed.filter(
        (item) =>
          item &&
          typeof item.provider === 'string' &&
          typeof item.id === 'string' &&
          typeof item.name === 'string',
      );
    } catch {
      return [];
    }
  }

  private persist(): void {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(this.favoritesSignal()));
  }
}
