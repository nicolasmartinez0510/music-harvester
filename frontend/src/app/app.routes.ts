import { Routes } from '@angular/router';

import { BrowseAlbumComponent } from './pages/browse/browse-album.component';
import { BrowseArtistComponent } from './pages/browse/browse-artist.component';
import { BrowsePlaylistComponent } from './pages/browse/browse-playlist.component';
import { BrowseComponent } from './pages/browse/browse.component';
import { DownloadsComponent } from './pages/downloads/downloads.component';
import { PlaylistDetailComponent } from './pages/playlist-detail/playlist-detail.component';
import { PlaylistsComponent } from './pages/playlists/playlists.component';
import { SettingsComponent } from './pages/settings/settings.component';

export const routes: Routes = [
  { path: '', pathMatch: 'full', redirectTo: 'downloads' },
  { path: 'downloads', component: DownloadsComponent },
  { path: 'browse', component: BrowseComponent },
  { path: 'browse/:provider/artists/:id', component: BrowseArtistComponent },
  { path: 'browse/:provider/albums/:id', component: BrowseAlbumComponent },
  { path: 'browse/:provider/playlists/:id', component: BrowsePlaylistComponent },
  { path: 'playlists', component: PlaylistsComponent },
  { path: 'playlists/:id', component: PlaylistDetailComponent },
  { path: 'settings', component: SettingsComponent },
  { path: '**', redirectTo: 'downloads' },
];
