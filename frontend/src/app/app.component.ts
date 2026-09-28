import { Component, inject } from '@angular/core';
import { RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';

import { ArtistFavoritesService } from './core/artist-favorites.service';
import { ThemeService } from './core/theme.service';
import { IconComponent } from './shared/icon.component';
import { ToastHostComponent } from './shared/toast-host.component';

@Component({
  selector: 'app-root',
  imports: [RouterOutlet, RouterLink, RouterLinkActive, ToastHostComponent, IconComponent],
  templateUrl: './app.component.html',
  styleUrl: './app.component.css',
})
export class AppComponent {
  readonly theme = inject(ThemeService);
  readonly favorites = inject(ArtistFavoritesService);
  sidebarOpen = false;

  openSidebar(): void {
    this.sidebarOpen = true;
  }

  closeSidebar(): void {
    this.sidebarOpen = false;
  }

  toggleTheme(): void {
    this.theme.toggle();
  }
}
