import { Component, inject } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';

import { ApiService } from '../../core/api.service';
import {
  ApiValidationError,
  AUDIO_FORMATS,
  AudioFormat,
  detectProviderFromUrl,
  PROVIDER_LABELS,
  ProviderInfo,
  ProviderName,
} from '../../core/models';

@Component({
  selector: 'app-download',
  imports: [ReactiveFormsModule, RouterLink],
  templateUrl: './download.component.html',
  styleUrl: './download.component.css',
})
export class DownloadComponent {
  private readonly api = inject(ApiService);
  private readonly fb = inject(FormBuilder);
  private readonly router = inject(Router);

  readonly formats = AUDIO_FORMATS;
  readonly providerLabels = PROVIDER_LABELS;
  submitting = false;
  errorMessage: string | null = null;
  providers: ProviderInfo[] = [];
  detectedProvider: ProviderName | null = null;

  readonly form = this.fb.nonNullable.group({
    url: ['', [Validators.required, Validators.maxLength(2048)]],
    format: ['mp3_320' as AudioFormat, Validators.required],
    provider: ['auto' as ProviderName, Validators.required],
  });

  constructor() {
    this.api.getProviders().subscribe({
      next: (providers) => {
        this.providers = providers;
      },
    });

    this.form.controls.url.valueChanges.subscribe((url) => {
      this.detectedProvider = detectProviderFromUrl(url);
    });
  }

  availableFormats(): { value: AudioFormat; label: string }[] {
    const selected = this.form.controls.provider.value;
    const effective =
      selected === 'auto' ? this.detectedProvider : selected;
    if (!effective) {
      return this.formats;
    }
    const info = this.providers.find((p) => p.name === effective);
    if (!info?.qualities?.length) {
      return this.formats;
    }
    return this.formats.filter((f) => info.qualities.includes(f.value));
  }

  detectedLabel(): string {
    if (!this.detectedProvider) {
      return 'Sin detectar';
    }
    return this.providerLabels[this.detectedProvider] ?? this.detectedProvider;
  }

  submit(): void {
    this.errorMessage = null;

    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }

    this.submitting = true;
    const { url, format, provider } = this.form.getRawValue();

    this.api
      .createDownload({
        url,
        format,
        provider: provider === 'auto' ? null : provider,
      })
      .subscribe({
        next: () => {
          this.submitting = false;
          this.form.reset({ url: '', format: 'mp3_320', provider: 'auto' });
          void this.router.navigate(['/downloads']);
        },
        error: (error: { error?: ApiValidationError }) => {
          this.submitting = false;
          const body = error.error;
          this.errorMessage =
            body?.message ??
            body?.errors?.['url']?.[0] ??
            'No se pudo encolar la descarga.';
        },
      });
  }
}
