import { Component, inject, OnInit } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';

import { ApiService } from '../../core/api.service';
import { ApiValidationError, AUDIO_FORMATS, AudioFormat } from '../../core/models';

@Component({
  selector: 'app-settings',
  imports: [ReactiveFormsModule],
  templateUrl: './settings.component.html',
  styleUrl: './settings.component.css',
})
export class SettingsComponent implements OnInit {
  private readonly api = inject(ApiService);
  private readonly fb = inject(FormBuilder);

  readonly formats = AUDIO_FORMATS;
  loading = true;
  saving = false;
  errorMessage: string | null = null;
  successMessage: string | null = null;
  cookiesConfigured = false;

  readonly form = this.fb.nonNullable.group({
    music_path: ['', [Validators.required, Validators.maxLength(500)]],
    default_format: ['mp3_320' as AudioFormat, Validators.required],
    max_concurrency: [1, [Validators.required, Validators.min(1), Validators.max(10)]],
    cookies_path: [''],
  });

  ngOnInit(): void {
    this.api.getSettings().subscribe({
      next: (settings) => {
        this.cookiesConfigured = settings.cookies_configured;
        this.form.patchValue({
          music_path: settings.music_path,
          default_format: settings.default_format,
          max_concurrency: settings.max_concurrency,
          cookies_path: settings.cookies_path ?? '',
        });
        this.loading = false;
      },
      error: () => {
        this.loading = false;
        this.errorMessage = 'No se pudo cargar la configuración.';
      },
    });
  }

  submit(): void {
    this.errorMessage = null;
    this.successMessage = null;

    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }

    const { music_path, default_format, max_concurrency, cookies_path } =
      this.form.getRawValue();

    this.saving = true;
    this.api
      .updateSettings({
        music_path,
        default_format,
        max_concurrency,
        cookies_path: cookies_path.trim() === '' ? null : cookies_path.trim(),
      })
      .subscribe({
        next: (settings) => {
          this.saving = false;
          this.cookiesConfigured = settings.cookies_configured;
          this.successMessage = 'Configuración guardada.';
        },
        error: (error: { error?: ApiValidationError }) => {
          this.saving = false;
          const body = error.error;
          this.errorMessage =
            body?.message ??
            Object.values(body?.errors ?? {})[0]?.[0] ??
            'No se pudo guardar la configuración.';
        },
      });
  }
}
