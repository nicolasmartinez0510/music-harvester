import { Routes } from '@angular/router';

import { DownloadComponent } from './pages/download/download.component';
import { DownloadsComponent } from './pages/downloads/downloads.component';
import { PlaylistDetailComponent } from './pages/playlist-detail/playlist-detail.component';
import { PlaylistsComponent } from './pages/playlists/playlists.component';
import { SettingsComponent } from './pages/settings/settings.component';

export const routes: Routes = [
  { path: '', component: DownloadComponent },
  { path: 'downloads', component: DownloadsComponent },
  { path: 'playlists', component: PlaylistsComponent },
  { path: 'playlists/:id', component: PlaylistDetailComponent },
  { path: 'settings', component: SettingsComponent },
  { path: '**', redirectTo: '' },
];
