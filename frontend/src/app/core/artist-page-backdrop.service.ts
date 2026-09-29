import { Injectable, signal } from '@angular/core';

@Injectable({ providedIn: 'root' })
export class ArtistPageBackdropService {
  readonly coverUrl = signal<string | null>(null);

  set(url: string | null): void {
    this.coverUrl.set(url);
  }

  clear(): void {
    this.coverUrl.set(null);
  }
}
