import { Routes } from '@angular/router';

import { adminGuard, authGuard, guestGuard } from './core/auth.guard';
import { ForgotPasswordComponent } from './pages/auth/forgot-password.component';
import { LoginComponent } from './pages/auth/login.component';
import { RegisterComponent } from './pages/auth/register.component';
import { ResetPasswordComponent } from './pages/auth/reset-password.component';
import { VerifyEmailComponent } from './pages/auth/verify-email.component';
import { BrowseAlbumComponent } from './pages/browse/browse-album.component';
import { BrowseArtistComponent } from './pages/browse/browse-artist.component';
import { BrowsePlaylistComponent } from './pages/browse/browse-playlist.component';
import { BrowseComponent } from './pages/browse/browse.component';
import { CollectionComponent } from './pages/collection/collection.component';
import { DownloadsComponent } from './pages/downloads/downloads.component';
import { PlaylistDetailComponent } from './pages/playlist-detail/playlist-detail.component';
import { PlaylistsComponent } from './pages/playlists/playlists.component';
import { SettingsComponent } from './pages/settings/settings.component';
import { UsersComponent } from './pages/users/users.component';

export const routes: Routes = [
  { path: 'login', component: LoginComponent, canActivate: [guestGuard] },
  { path: 'register', component: RegisterComponent, canActivate: [guestGuard] },
  { path: 'verify-email', component: VerifyEmailComponent, canActivate: [guestGuard] },
  { path: 'forgot-password', component: ForgotPasswordComponent, canActivate: [guestGuard] },
  { path: 'reset-password', component: ResetPasswordComponent },
  { path: '', pathMatch: 'full', redirectTo: 'downloads' },
  { path: 'downloads', component: DownloadsComponent, canActivate: [authGuard] },
  { path: 'browse', component: BrowseComponent, canActivate: [authGuard] },
  { path: 'browse/:provider/artists/:id', component: BrowseArtistComponent, canActivate: [authGuard] },
  { path: 'browse/:provider/albums/:id', component: BrowseAlbumComponent, canActivate: [authGuard] },
  { path: 'browse/:provider/playlists/:id', component: BrowsePlaylistComponent, canActivate: [authGuard] },
  { path: 'collection/:provider/:kind', component: CollectionComponent, canActivate: [authGuard] },
  { path: 'playlists', component: PlaylistsComponent, canActivate: [authGuard] },
  { path: 'playlists/:id', component: PlaylistDetailComponent, canActivate: [authGuard] },
  { path: 'settings', component: SettingsComponent, canActivate: [authGuard] },
  { path: 'users', component: UsersComponent, canActivate: [adminGuard] },
  { path: '**', redirectTo: 'downloads' },
];
