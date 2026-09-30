import { Component, computed, inject, input } from '@angular/core';

import { CatalogActionsService, CatalogDownloadUiState } from '../core/catalog-actions.service';
import { IconComponent, IconName } from './icon.component';

@Component({
  selector: 'app-catalog-download-btn',
  imports: [IconComponent],
  template: `
    <button
      type="button"
      class="btn btn-with-icon"
      [class.btn-small]="size() === 'small'"
      [class.btn-primary]="variant() === 'primary' && state() === 'idle'"
      [class.btn-downloaded]="state() === 'done'"
      [disabled]="!url() || state() === 'queued' || state() === 'done'"
      (click)="onClick()"
      [attr.title]="title()"
      [attr.aria-label]="buttonLabel()"
    >
      <app-icon [name]="iconName()" class="btn-leading-icon" />
      <span class="btn-label">{{ buttonLabel() }}</span>
    </button>
  `,
  styles: `
    :host {
      display: inline-flex;
    }

    .btn-downloaded {
      color: var(--success);
      border-color: color-mix(in srgb, var(--success) 35%, var(--border));
      background: var(--success-soft);
    }

    .btn-downloaded:disabled {
      opacity: 1;
      cursor: default;
    }

    @media (max-width: 640px) {
      :host-context(.hit) .btn-label {
        display: none;
      }

      :host-context(.hit) .btn {
        width: 2.75rem;
        height: 2.75rem;
        padding: 0;
      }

      :host-context(.hit) .btn-leading-icon {
        margin-right: 0;
      }
    }
  `,
})
export class CatalogDownloadButtonComponent {
  private readonly actions = inject(CatalogActionsService);

  readonly url = input<string | null | undefined>();
  readonly parentUrl = input<string | null | undefined>();
  readonly label = input('Descargar');
  readonly size = input<'small' | 'default'>('small');
  readonly variant = input<'default' | 'primary'>('default');

  readonly state = computed<CatalogDownloadUiState>(() => {
    const own = this.actions.status(this.url());
    if (own !== 'idle') {
      return own;
    }

    const parent = this.actions.status(this.parentUrl());
    if (parent === 'queued' || parent === 'done') {
      return parent;
    }

    return 'idle';
  });

  readonly buttonLabel = computed(() => {
    const state = this.state();
    if (state === 'queued') {
      return 'Descargando…';
    }
    if (state === 'done') {
      return 'Descargado';
    }
    return this.label();
  });

  readonly title = computed(() => {
    const state = this.state();
    if (state === 'queued') {
      return 'Descarga en curso';
    }
    if (state === 'done') {
      return 'Ya descargado';
    }
    return this.label();
  });

  readonly iconName = computed<IconName>(() => {
    const state = this.state();
    if (state === 'queued') {
      return 'spinner';
    }
    if (state === 'done') {
      return 'check';
    }
    return 'download';
  });

  onClick(): void {
    this.actions.downloadNow(this.url());
  }
}
