import { Component, OnInit, inject } from '@angular/core';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';

import { ApiService } from '../../core/api.service';
import { CatalogAlbum } from '../../core/models';
import { CatalogDownloadButtonComponent } from '../../shared/catalog-download-button.component';
import { PageLoadingComponent } from '../../shared/page-loading.component';

@Component({
  selector: 'app-browse-album',
  imports: [RouterLink, CatalogDownloadButtonComponent, PageLoadingComponent],
  templateUrl: './browse-album.component.html',
  styleUrl: './browse-detail.css',
})
export class BrowseAlbumComponent implements OnInit {
  private readonly api = inject(ApiService);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);

  provider = '';
  album: CatalogAlbum | null = null;
  loading = true;
  errorMessage: string | null = null;

  ngOnInit(): void {
    this.provider = this.route.snapshot.paramMap.get('provider') ?? '';
    const id = this.route.snapshot.paramMap.get('id') ?? '';

    if (!this.provider || !id) {
      void this.router.navigate(['/browse']);
      return;
    }

    this.api.getCatalogAlbum(this.provider, id).subscribe({
      next: (album) => {
        this.album = album;
        this.loading = false;
      },
      error: (error: { error?: { message?: string } }) => {
        this.loading = false;
        this.errorMessage = error.error?.message ?? 'No se pudo cargar el álbum.';
      },
    });
  }
}
