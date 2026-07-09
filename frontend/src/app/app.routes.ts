import { Routes } from '@angular/router';

import { DownloadComponent } from './pages/download/download.component';
import { DownloadsComponent } from './pages/downloads/downloads.component';
import { SettingsComponent } from './pages/settings/settings.component';

export const routes: Routes = [
  { path: '', component: DownloadComponent },
  { path: 'downloads', component: DownloadsComponent },
  { path: 'settings', component: SettingsComponent },
  { path: '**', redirectTo: '' },
];
