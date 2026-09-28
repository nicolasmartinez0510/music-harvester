import { Injectable, computed, signal } from '@angular/core';

import { CatalogType } from './models';

export interface SearchHistoryEntry {
  q: string;
  provider: string;
  type: CatalogType;
  at: number;
}

const STORAGE_KEY = 'mh-search-history';
const MAX_ENTRIES = 50;

@Injectable({ providedIn: 'root' })
export class SearchHistoryService {
  private readonly entriesSignal = signal<SearchHistoryEntry[]>(this.load());

  readonly entries = this.entriesSignal.asReadonly();
  readonly recent = computed(() => this.entriesSignal().slice(0, 3));

  list(): SearchHistoryEntry[] {
    return this.entriesSignal();
  }

  push(entry: Omit<SearchHistoryEntry, 'at'>): void {
    const q = entry.q.trim();
    if (!q) {
      return;
    }

    const next: SearchHistoryEntry = {
      q,
      provider: entry.provider,
      type: entry.type,
      at: Date.now(),
    };

    this.entriesSignal.update((list) => {
      const filtered = list.filter(
        (item) =>
          !(
            item.q.toLowerCase() === next.q.toLowerCase() &&
            item.provider === next.provider &&
            item.type === next.type
          ),
      );
      return [next, ...filtered].slice(0, MAX_ENTRIES);
    });
    this.persist();
  }

  remove(at: number): void {
    this.entriesSignal.update((list) => list.filter((item) => item.at !== at));
    this.persist();
  }

  clear(): void {
    this.entriesSignal.set([]);
    this.persist();
  }

  private load(): SearchHistoryEntry[] {
    try {
      const raw = localStorage.getItem(STORAGE_KEY);
      if (!raw) {
        return [];
      }
      const parsed = JSON.parse(raw) as SearchHistoryEntry[];
      if (!Array.isArray(parsed)) {
        return [];
      }
      return parsed
        .filter(
          (item) =>
            item &&
            typeof item.q === 'string' &&
            typeof item.provider === 'string' &&
            typeof item.type === 'string' &&
            typeof item.at === 'number',
        )
        .slice(0, MAX_ENTRIES);
    } catch {
      return [];
    }
  }

  private persist(): void {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(this.entriesSignal()));
  }
}
