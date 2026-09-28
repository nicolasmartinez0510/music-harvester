import { Component, OnInit, effect, inject } from '@angular/core';
import { Router, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';

import { ApiService } from './core/api.service';
import { ArtistFavoritesService } from './core/artist-favorites.service';
import { AuthService } from './core/auth.service';
import {
  LIBRARY_KIND_LABELS,
  LibraryKind,
  PROVIDER_LABELS,
  ProviderInfo,
  providerIconName,
} from './core/models';
import { ThemeService } from './core/theme.service';
import { IconComponent, IconName } from './shared/icon.component';
import { ToastHostComponent } from './shared/toast-host.component';

const SIDEBAR_COLLAPSED_KEY = 'mh-sidebar-collapsed';
const FAVORITES_EXPANDED_KEY = 'mh-favorites-expanded';
const COLLECTION_PROVIDERS_KEY = 'mh-collection-providers-expanded';

@Component({
  selector: 'app-root',
  imports: [RouterOutlet, RouterLink, RouterLinkActive, ToastHostComponent, IconComponent],
  templateUrl: './app.component.html',
  styleUrl: './app.component.css',
})
export class AppComponent implements OnInit {
  readonly theme = inject(ThemeService);
  readonly favorites = inject(ArtistFavoritesService);
  readonly auth = inject(AuthService);
  private readonly api = inject(ApiService);
  private readonly router = inject(Router);

  readonly providerLabels = PROVIDER_LABELS;
  readonly libraryKinds: LibraryKind[] = ['artists', 'tracks', 'albums', 'playlists'];
  readonly libraryKindLabels = LIBRARY_KIND_LABELS;

  sidebarOpen = false;
  sidebarCollapsed = this.readCollapsed();
  favoritesExpanded = this.readFavoritesExpanded();
  libraryProviders: ProviderInfo[] = [];
  private expandedProviders = this.readExpandedProviders();

  constructor() {
    effect(() => {
      const user = this.auth.user();
      if (user) {
        this.refreshLibraryProviders();
        this.favorites.load();
      } else {
        this.libraryProviders = [];
        this.favorites.clear();
      }
    });
  }

  ngOnInit(): void {
    this.auth.bootstrap().subscribe();
  }

  logout(): void {
    this.auth.logout().subscribe({
      next: () => {
        void this.router.navigate(['/login']);
      },
    });
  }

  openSidebar(): void {
    this.sidebarOpen = true;
    this.refreshLibraryProviders();
  }

  closeSidebar(): void {
    this.sidebarOpen = false;
  }

  toggleSidebarCollapsed(): void {
    this.sidebarCollapsed = !this.sidebarCollapsed;
    localStorage.setItem(SIDEBAR_COLLAPSED_KEY, this.sidebarCollapsed ? '1' : '0');
  }

  toggleFavoritesExpanded(): void {
    this.favoritesExpanded = !this.favoritesExpanded;
    localStorage.setItem(FAVORITES_EXPANDED_KEY, this.favoritesExpanded ? '1' : '0');
  }

  isProviderExpanded(name: string): boolean {
    return this.expandedProviders.has(name);
  }

  toggleProviderExpanded(name: string): void {
    if (this.expandedProviders.has(name)) {
      this.expandedProviders.delete(name);
    } else {
      this.expandedProviders.add(name);
    }
    localStorage.setItem(COLLECTION_PROVIDERS_KEY, JSON.stringify([...this.expandedProviders]));
  }

  providerLabel(name: string): string {
    return this.providerLabels[name] ?? name;
  }

  providerIcon(name: string): IconName {
    return providerIconName(name);
  }

  toggleTheme(): void {
    this.theme.toggle();
  }

  private refreshLibraryProviders(): void {
    this.api.getProviders().subscribe({
      next: (providers) => {
        this.libraryProviders = providers.filter((p) => p.has_library);
      },
    });
  }

  private readCollapsed(): boolean {
    return localStorage.getItem(SIDEBAR_COLLAPSED_KEY) === '1';
  }

  private readFavoritesExpanded(): boolean {
    return localStorage.getItem(FAVORITES_EXPANDED_KEY) !== '0';
  }

  private readExpandedProviders(): Set<string> {
    try {
      const raw = localStorage.getItem(COLLECTION_PROVIDERS_KEY);
      if (!raw) {
        return new Set(['deezer']);
      }
      const parsed = JSON.parse(raw) as unknown;
      if (!Array.isArray(parsed)) {
        return new Set(['deezer']);
      }
      return new Set(parsed.filter((v): v is string => typeof v === 'string'));
    } catch {
      return new Set(['deezer']);
    }
  }
}
