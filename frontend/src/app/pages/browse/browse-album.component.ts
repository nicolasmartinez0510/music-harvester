import { Component, OnInit, inject } from '@angular/core';
import { ActivatedRoute, ParamMap, Router, RouterLink } from '@angular/router';

import { ApiService } from '../../core/api.service';
import { CatalogAlbum } from '../../core/models';
import { CatalogDownloadButtonComponent } from '../../shared/catalog-download-button.component';
import { CatalogPreviewButtonComponent } from '../../shared/catalog-preview-button.component';
import { PageLoadingComponent } from '../../shared/page-loading.component';

@Component({
  selector: 'app-browse-album',
  imports: [RouterLink, CatalogDownloadButtonComponent, CatalogPreviewButtonComponent, PageLoadingComponent],
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
  fromCollection = false;
  fromArtist = false;
  artistId = '';
  artistBackQuery: Record<string, string> = {};

  ngOnInit(): void {
    this.provider = this.route.snapshot.paramMap.get('provider') ?? '';
    const id = this.route.snapshot.paramMap.get('id') ?? '';
    const query = this.route.snapshot.queryParamMap;
    this.fromCollection = query.get('from') === 'collection';
    this.artistId = query.get('artistId') ?? '';
    this.fromArtist = query.get('from') === 'artist' && this.artistId !== '';
    this.artistBackQuery = this.buildArtistBackQuery(query);

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

  get backLabel(): string {
    return this.album?.artist_name || 'Artista';
  }

  private buildArtistBackQuery(query: ParamMap): Record<string, string> {
    const back: Record<string, string> = {};
    for (const key of ['tab', 'release', 'albumPage', 'singlePage', 'trackPage', 'albumSort'] as const) {
      const value = query.get(key);
      if (value) {
        back[key] = value;
      }
    }
    const artistFrom = query.get('artistFrom');
    if (artistFrom === 'favorites' || artistFrom === 'collection') {
      back['from'] = artistFrom;
    }
    return back;
  }
}
