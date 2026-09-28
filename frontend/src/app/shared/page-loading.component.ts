import { Component, input } from '@angular/core';

import { IconComponent } from './icon.component';

@Component({
  selector: 'app-page-loading',
  imports: [IconComponent],
  template: `
    <div class="page-loading" role="status" aria-live="polite" [attr.aria-label]="label()">
      <div class="page-loading-panel">
        <app-icon name="spinner" [size]="28" />
        <span>{{ label() }}</span>
      </div>
    </div>
  `,
  styles: `
    .page-loading {
      position: fixed;
      top: 0;
      right: 0;
      bottom: 0;
      left: var(--sidebar-width);
      z-index: 25;
      display: grid;
      place-items: center;
      padding: 1.5rem;
      background: color-mix(in srgb, var(--bg) 48%, transparent);
      backdrop-filter: blur(7px);
      -webkit-backdrop-filter: blur(7px);
    }

    .page-loading-panel {
      display: inline-flex;
      align-items: center;
      gap: 0.75rem;
      padding: 0.9rem 1.15rem;
      border-radius: calc(var(--radius) + 0.15rem);
      border: 1px solid var(--border);
      background: color-mix(in srgb, var(--surface) 88%, transparent);
      box-shadow: var(--shadow-lg);
      color: var(--text);
      font-weight: 600;
      font-size: 0.95rem;
    }

    @media (max-width: 840px) {
      .page-loading {
        left: 0;
      }
    }
  `,
})
export class PageLoadingComponent {
  readonly label = input('Cargando…');
}
