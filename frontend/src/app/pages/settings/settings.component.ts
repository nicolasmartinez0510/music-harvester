import { Component, inject, OnInit } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';

import { ApiService } from '../../core/api.service';
import { AuthService } from '../../core/auth.service';
import {
  ApiValidationError,
  AUDIO_FORMATS,
  AudioFormat,
  DeezerMode,
} from '../../core/models';
import { PageLoadingComponent } from '../../shared/page-loading.component';

type SettingsTab = 'general' | 'youtube_music' | 'deezer' | 'download';

@Component({
  selector: 'app-settings',
  imports: [ReactiveFormsModule, PageLoadingComponent],
  templateUrl: './settings.component.html',
  styleUrl: './settings.component.css',
})
export class SettingsComponent implements OnInit {
  private readonly api = inject(ApiService);
  private readonly auth = inject(AuthService);
  private readonly fb = inject(FormBuilder);

  readonly formats = AUDIO_FORMATS;

  activeTab: SettingsTab = 'download';
  loading = true;
  saving = false;
  errorMessage: string | null = null;
  successMessage: string | null = null;
  youtubeCookiesConfigured = false;
  deezerArlConfigured = false;
  isAdmin = false;
  serverStorageStatus: 'none' | 'pending' | 'approved' = 'none';
  effectiveDestination: 'direct' | 'server' = 'direct';
  libraryRoot = '';
  cookiesFile: File | null = null;

  readonly form = this.fb.nonNullable.group({
    music_path: ['', [Validators.required, Validators.maxLength(500)]],
    default_format: ['mp3_320' as AudioFormat, Validators.required],
    max_concurrency: [1, [Validators.required, Validators.min(1), Validators.max(10)]],
    provider_deezer_arl: [''],
    provider_deezer_mode: ['native' as DeezerMode, Validators.required],
    download_destination: ['direct' as 'direct' | 'server', Validators.required],
    email_verification_enabled: [true],
    metadata_enrich_enabled: [true],
    metadata_embed_cover: [true],
    metadata_embed_lyrics: [true],
  });

  get tabs(): { id: SettingsTab; label: string }[] {
    const items: { id: SettingsTab; label: string }[] = [
      { id: 'download', label: 'Descarga' },
      { id: 'youtube_music', label: 'YouTube Music' },
      { id: 'deezer', label: 'Deezer' },
    ];
    if (this.isAdmin) {
      return [{ id: 'general', label: 'General' }, ...items];
    }
    return items;
  }

  ngOnInit(): void {
    this.isAdmin = this.auth.user()?.role === 'admin';
    if (this.isAdmin) {
      this.activeTab = 'general';
    }
    this.api.getSettings().subscribe({
      next: (settings) => {
        this.youtubeCookiesConfigured = settings.provider_youtube_music_cookies_configured;
        this.deezerArlConfigured = settings.provider_deezer_arl_configured;
        this.isAdmin = settings.is_admin;
        this.serverStorageStatus = settings.server_storage_status;
        this.effectiveDestination = settings.effective_download_destination;
        this.libraryRoot = settings.library_root;
        this.form.patchValue({
          music_path: settings.music_path,
          default_format: settings.default_format,
          max_concurrency: settings.max_concurrency,
          provider_deezer_arl: '',
          provider_deezer_mode: settings.provider_deezer_mode ?? 'native',
          download_destination: settings.download_destination,
          email_verification_enabled: settings.email_verification_enabled,
          metadata_enrich_enabled: settings.metadata_enrich_enabled,
          metadata_embed_cover: settings.metadata_embed_cover,
          metadata_embed_lyrics: settings.metadata_embed_lyrics,
        });
        this.auth.emailVerificationEnabled.set(settings.email_verification_enabled);
        this.loading = false;
      },
      error: () => {
        this.loading = false;
        this.errorMessage = 'No se pudo cargar la configuración.';
      },
    });
  }

  selectTab(tab: SettingsTab): void {
    this.activeTab = tab;
    this.errorMessage = null;
    this.successMessage = null;
  }

  onCookiesSelected(event: Event): void {
    const input = event.target as HTMLInputElement;
    this.cookiesFile = input.files?.[0] ?? null;
  }

  submit(): void {
    this.errorMessage = null;
    this.successMessage = null;

    if (this.activeTab === 'youtube_music') {
      this.saveCookies();
      return;
    }

    if (this.activeTab === 'deezer' && !this.isAdmin) {
      this.saveArl();
      return;
    }

    if (this.activeTab === 'download') {
      this.saveDestination();
      return;
    }

    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }

    const raw = this.form.getRawValue();
    this.saving = true;

    if (this.activeTab === 'deezer') {
      const arl = raw.provider_deezer_arl.trim();
      const saveMode = () =>
        this.api
          .updateSettings({
            provider_deezer_mode: raw.provider_deezer_mode,
            metadata_enrich_enabled: raw.metadata_enrich_enabled,
            metadata_embed_cover: raw.metadata_embed_cover,
            metadata_embed_lyrics: raw.metadata_embed_lyrics,
            ...(arl !== '' ? { provider_deezer_arl: arl } : {}),
          })
          .subscribe({
            next: (settings) => {
              this.saving = false;
              this.deezerArlConfigured = settings.provider_deezer_arl_configured || arl !== '';
              this.form.patchValue({ provider_deezer_arl: '' });
              this.successMessage = 'Configuración guardada.';
            },
            error: (error: { error?: ApiValidationError }) => this.fail(error),
          });

      if (arl !== '') {
        this.api.updateDeezerArl(arl).subscribe({
          next: () => saveMode(),
          error: (error: { error?: ApiValidationError }) => this.fail(error),
        });
        return;
      }

      saveMode();
      return;
    }

    this.api
      .updateSettings({
        music_path: raw.music_path,
        default_format: raw.default_format,
        max_concurrency: raw.max_concurrency,
        email_verification_enabled: raw.email_verification_enabled,
      })
      .subscribe({
        next: (settings) => {
          this.saving = false;
          this.auth.emailVerificationEnabled.set(settings.email_verification_enabled);
          this.successMessage = 'Configuración guardada.';
        },
        error: (error: { error?: ApiValidationError }) => this.fail(error),
      });
  }

  private saveCookies(): void {
    if (!this.cookiesFile) {
      this.errorMessage = 'Elegí un archivo de cookies.';
      return;
    }
    this.saving = true;
    this.api.uploadYoutubeCookies(this.cookiesFile).subscribe({
      next: () => {
        this.saving = false;
        this.youtubeCookiesConfigured = true;
        this.cookiesFile = null;
        this.successMessage = 'Cookies guardadas.';
      },
      error: (error: { error?: ApiValidationError }) => this.fail(error),
    });
  }

  private saveArl(): void {
    const arl = this.form.controls.provider_deezer_arl.value.trim();
    if (arl === '') {
      this.errorMessage = 'Pegá el ARL para guardarlo.';
      return;
    }
    this.saving = true;
    this.api.updateDeezerArl(arl).subscribe({
      next: () => {
        this.saving = false;
        this.deezerArlConfigured = true;
        this.form.patchValue({ provider_deezer_arl: '' });
        this.successMessage = 'ARL guardado.';
      },
      error: (error: { error?: ApiValidationError }) => this.fail(error),
    });
  }

  private saveDestination(): void {
    this.saving = true;
    const destination = this.form.controls.download_destination.value;
    this.api.updatePreferences({ download_destination: destination }).subscribe({
      next: (user) => {
        this.saving = false;
        this.auth.setUser(user);
        this.serverStorageStatus = user.server_storage_status;
        this.effectiveDestination =
          user.download_destination === 'server' &&
          (user.role === 'admin' || user.server_storage_status === 'approved')
            ? 'server'
            : 'direct';
        this.successMessage = 'Preferencia guardada.';
      },
      error: (error: { error?: ApiValidationError }) => this.fail(error),
    });
  }

  private fail(error: { error?: ApiValidationError }): void {
    this.saving = false;
    const body = error.error;
    this.errorMessage =
      body?.message ?? Object.values(body?.errors ?? {})[0]?.[0] ?? 'No se pudo guardar la configuración.';
  }
}
