import { Component, inject } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';

import { ApiService } from '../../core/api.service';
import { ApiValidationError, AUDIO_FORMATS, AudioFormat } from '../../core/models';

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
  submitting = false;
  errorMessage: string | null = null;

  readonly form = this.fb.nonNullable.group({
    url: ['', [Validators.required, Validators.maxLength(2048)]],
    format: ['mp3_320' as const, Validators.required],
  });

  submit(): void {
    this.errorMessage = null;

    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }

    this.submitting = true;
    const { url, format } = this.form.getRawValue();

    this.api.createDownload({ url, format }).subscribe({
      next: () => {
        this.submitting = false;
        this.form.reset({ url: '', format: 'mp3_320' });
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
