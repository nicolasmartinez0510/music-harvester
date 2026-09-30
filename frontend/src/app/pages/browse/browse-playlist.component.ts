import { Component, OnInit, inject } from '@angular/core';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';

import { ApiService } from '../../core/api.service';
import { CatalogActionsService } from '../../core/catalog-actions.service';
import { CatalogPlaylist } from '../../core/models';
import { CatalogDownloadButtonComponent } from '../../shared/catalog-download-button.component';
import { CatalogPreviewButtonComponent } from '../../shared/catalog-preview-button.component';
import { IconComponent } from '../../shared/icon.component';
import { PageLoadingComponent } from '../../shared/page-loading.component';

@Component({
  selector: 'app-browse-playlist',
  imports: [RouterLink, IconComponent, CatalogDownloadButtonComponent, CatalogPreviewButtonComponent, PageLoadingComponent],
  templateUrl: './browse-playlist.component.html',
  styleUrl: './browse-detail.css',
})
export class BrowsePlaylistComponent implements OnInit {
  private readonly api = inject(ApiService);
  private readonly actions = inject(CatalogActionsService);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);

  provider = '';
  playlist: CatalogPlaylist | null = null;
  loading = true;
  starring = false;
  errorMessage: string | null = null;
  fromCollection = false;

  ngOnInit(): void {
    this.provider = this.route.snapshot.paramMap.get('provider') ?? '';
    const id = this.route.snapshot.paramMap.get('id') ?? '';
    this.fromCollection = this.route.snapshot.queryParamMap.get('from') === 'collection';

    if (!this.provider || !id) {
      void this.router.navigate(['/browse']);
      return;
    }

    this.api.getCatalogPlaylist(this.provider, id).subscribe({
      next: (playlist) => {
        this.playlist = playlist;
        this.loading = false;
      },
      error: (error: { error?: { message?: string } }) => {
        this.loading = false;
        this.errorMessage = error.error?.message ?? 'No se pudo cargar la playlist.';
      },
    });
  }

  star(): void {
    if (this.starring) {
      return;
    }
    this.starring = true;
    this.actions.starPlaylist(this.playlist?.canonical_url).subscribe({
      next: () => {
        this.starring = false;
      },
      error: () => {
        this.starring = false;
      },
      complete: () => {
        this.starring = false;
      },
    });
  }
}
