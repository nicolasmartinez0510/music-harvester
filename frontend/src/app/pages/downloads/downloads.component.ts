import { DatePipe } from '@angular/common';
import { Component, DestroyRef, inject, OnInit } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { RouterLink } from '@angular/router';
import { interval, startWith, switchMap } from 'rxjs';

import { ApiService } from '../../core/api.service';
import { DownloadJob, STATUS_LABELS } from '../../core/models';

@Component({
  selector: 'app-downloads',
  imports: [DatePipe, RouterLink],
  templateUrl: './downloads.component.html',
  styleUrl: './downloads.component.css',
})
export class DownloadsComponent implements OnInit {
  private readonly api = inject(ApiService);
  private readonly destroyRef = inject(DestroyRef);

  readonly statusLabels = STATUS_LABELS;
  jobs: DownloadJob[] = [];
  loading = true;
  errorMessage: string | null = null;
  retryingId: number | null = null;

  ngOnInit(): void {
    interval(5000)
      .pipe(
        startWith(0),
        switchMap(() => this.api.listDownloads()),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe({
        next: (jobs) => {
          this.jobs = jobs;
          this.loading = false;
          this.errorMessage = null;
        },
        error: () => {
          this.loading = false;
          this.errorMessage = 'No se pudo cargar la cola de descargas.';
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
      },
      error: () => {
        this.retryingId = null;
        this.errorMessage = `No se pudo reintentar el job #${job.id}.`;
      },
    });
  }

  statusClass(status: DownloadJob['status']): string {
    return `status status-${status}`;
  }
}
