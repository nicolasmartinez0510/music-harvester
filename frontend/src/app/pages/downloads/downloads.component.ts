import { DatePipe } from '@angular/common';
import { Component, DestroyRef, HostListener, OnDestroy, OnInit, inject } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { Subscription, interval, startWith, switchMap } from 'rxjs';

import { ApiService } from '../../core/api.service';
import { CatalogActionsService } from '../../core/catalog-actions.service';
import {
  ApiValidationError,
  AUDIO_FORMATS,
  AudioFormat,
  DownloadJob,
  PROVIDER_LABELS,
  ProviderInfo,
  ProviderName,
  STATUS_LABELS,
  detectProviderFromUrl,
} from '../../core/models';
import { IconComponent } from '../../shared/icon.component';
import { PageLoadingComponent } from '../../shared/page-loading.component';
import { PaginationComponent } from '../../shared/pagination.component';
import { ToastService } from '../../shared/toast.service';

@Component({
  selector: 'app-downloads',
  imports: [
    DatePipe,
    ReactiveFormsModule,
    IconComponent,
    PageLoadingComponent,
    PaginationComponent,
  ],
  templateUrl: './downloads.component.html',
  styleUrl: './downloads.component.css',
})
export class DownloadsComponent implements OnInit, OnDestroy {
  private readonly api = inject(ApiService);
  private readonly catalogActions = inject(CatalogActionsService);
  private readonly destroyRef = inject(DestroyRef);
  private readonly fb = inject(FormBuilder);
  private readonly toast = inject(ToastService);
  private urlSub: Subscription | null = null;

  readonly statusLabels = STATUS_LABELS;
  readonly allFormats = AUDIO_FORMATS;
  readonly providerLabels = PROVIDER_LABELS;

  jobs: DownloadJob[] = [];
  providers: ProviderInfo[] = [];
  detectedProvider: ProviderName | null = null;
  loading = true;
  errorMessage: string | null = null;
  retryingId: number | null = null;
  deletingId: number | null = null;
  clearing = false;
  showForm = false;
  showDeleteConfirm = false;
  showClearConfirm = false;
  jobPendingDelete: DownloadJob | null = null;
  submitting = false;
  formError: string | null = null;

  page = 1;
  pageSize = 10;

  readonly form = this.fb.nonNullable.group({
    url: ['', [Validators.required, Validators.maxLength(2048)]],
    format: ['mp3_320' as AudioFormat, Validators.required],
    provider: ['auto' as ProviderName, Validators.required],
  });

  ngOnInit(): void {
    this.api.getProviders().subscribe({
      next: (providers) => {
        this.providers = providers;
        this.syncFormats();
      },
    });

    this.urlSub = this.form.controls.url.valueChanges.subscribe((url) => {
      this.detectedProvider = detectProviderFromUrl(url);
      this.syncFormats();
    });

    this.form.controls.provider.valueChanges.subscribe(() => {
      this.syncFormats();
    });

    interval(5000)
      .pipe(
        startWith(0),
        switchMap(() => this.api.listDownloads(200)),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe({
        next: (jobs) => {
          this.jobs = jobs;
          this.catalogActions.applyJobsSnapshot(jobs);
          this.loading = false;
          this.errorMessage = null;
          this.clampPage();
        },
        error: () => {
          this.loading = false;
          this.errorMessage = 'No se pudo cargar la cola de descargas.';
        },
      });
  }

  ngOnDestroy(): void {
    this.urlSub?.unsubscribe();
  }

  get pagedJobs(): DownloadJob[] {
    const start = (this.page - 1) * this.pageSize;
    return this.jobs.slice(start, start + this.pageSize);
  }

  get availableFormats(): { value: AudioFormat; label: string }[] {
    const effective = this.effectiveProvider();
    if (!effective) {
      return this.allFormats;
    }
    const info = this.providers.find((p) => p.name === effective);
    if (!info) {
      return this.allFormats;
    }
    return this.allFormats.filter((f) => info.qualities.includes(f.value));
  }

  detectedLabel(): string {
    if (!this.detectedProvider) {
      return 'Sin detectar';
    }
    return this.providerLabels[this.detectedProvider] ?? this.detectedProvider;
  }

  effectiveProviderIsDeezer(): boolean {
    return this.effectiveProvider() === 'deezer';
  }

  displayTitle(job: DownloadJob): string {
    if (job.title) {
      return job.title;
    }
    return job.url;
  }

  onPageChange(page: number): void {
    this.page = page;
  }

  onPageSizeChange(size: number): void {
    this.pageSize = size;
    this.page = 1;
  }

  openForm(): void {
    this.formError = null;
    this.showForm = true;
  }

  cancelForm(): void {
    if (this.submitting) {
      return;
    }
    this.showForm = false;
    this.formError = null;
    this.form.reset({ url: '', format: 'mp3_320', provider: 'auto' });
    this.detectedProvider = null;
  }

  submit(): void {
    this.formError = null;

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
        next: (job) => {
          this.submitting = false;
          this.showForm = false;
          this.form.reset({ url: '', format: 'mp3_320', provider: 'auto' });
          this.detectedProvider = null;
          this.jobs = [job, ...this.jobs.filter((item) => item.id !== job.id)];
          this.catalogActions.applyJobsSnapshot(this.jobs);
          this.toast.success('Descarga encolada.');
          this.clampPage();
        },
        error: (error: { error?: ApiValidationError }) => {
          this.submitting = false;
          const body = error.error;
          this.formError =
            body?.message ??
            body?.errors?.['url']?.[0] ??
            'No se pudo encolar la descarga.';
        },
      });
  }

  retry(job: DownloadJob): void {
    if (job.status !== 'failed') {
      return;
    }

    this.retryingId = job.id;
    this.api.retryDownload(job.id).subscribe({
      next: (updated) => {
        this.retryingId = null;
        this.jobs = this.jobs.map((item) => (item.id === updated.id ? updated : item));
        this.catalogActions.applyJobsSnapshot(this.jobs);
      },
      error: () => {
        this.retryingId = null;
        this.errorMessage = `No se pudo reintentar el job #${job.id}.`;
      },
    });
  }

  remove(job: DownloadJob): void {
    this.jobPendingDelete = job;
    this.showDeleteConfirm = true;
  }

  cancelDelete(): void {
    if (this.deletingId !== null) {
      return;
    }
    this.showDeleteConfirm = false;
    this.jobPendingDelete = null;
  }

  confirmDelete(): void {
    const job = this.jobPendingDelete;
    if (!job || this.deletingId !== null) {
      return;
    }

    this.deletingId = job.id;
    this.api.deleteDownload(job.id).subscribe({
      next: () => {
        this.jobs = this.jobs.filter((item) => item.id !== job.id);
        this.catalogActions.applyJobsSnapshot(this.jobs);
        this.deletingId = null;
        this.showDeleteConfirm = false;
        this.jobPendingDelete = null;
        this.toast.success('Descarga eliminada del historial.');
        this.clampPage();
      },
      error: () => {
        this.deletingId = null;
        this.toast.error('No se pudo eliminar la descarga.');
      },
    });
  }

  openClearConfirm(): void {
    this.showClearConfirm = true;
  }

  cancelClear(): void {
    if (this.clearing) {
      return;
    }
    this.showClearConfirm = false;
  }

  confirmClear(): void {
    if (this.clearing) {
      return;
    }

    this.clearing = true;
    this.api.clearDownloads().subscribe({
      next: () => {
        this.jobs = [];
        this.catalogActions.applyJobsSnapshot([]);
        this.clearing = false;
        this.showClearConfirm = false;
        this.toast.success('Historial de descargas vaciado.');
        this.clampPage();
      },
      error: () => {
        this.clearing = false;
        this.toast.error('No se pudo vaciar el historial.');
      },
    });
  }

  statusClass(status: DownloadJob['status']): string {
    return `status status-${status}`;
  }

  @HostListener('document:keydown.escape')
  onEscape(): void {
    if (this.showDeleteConfirm && this.deletingId === null) {
      this.cancelDelete();
      return;
    }
    if (this.showClearConfirm && !this.clearing) {
      this.cancelClear();
      return;
    }
    if (this.showForm && !this.submitting) {
      this.cancelForm();
    }
  }

  private clampPage(): void {
    const totalPages = Math.max(1, Math.ceil(this.jobs.length / this.pageSize));
    if (this.page > totalPages) {
      this.page = totalPages;
    }
  }

  private effectiveProvider(): ProviderName | null {
    const selected = this.form.controls.provider.value;
    if (selected && selected !== 'auto') {
      return selected;
    }
    return this.detectedProvider;
  }

  private syncFormats(): void {
    const available = this.availableFormats.map((f) => f.value);
    const current = this.form.controls.format.value;
    if (!available.includes(current) && available.length > 0) {
      this.form.controls.format.setValue(available[0]);
    }
  }
}
