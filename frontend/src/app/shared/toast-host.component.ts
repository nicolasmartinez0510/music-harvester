import { AsyncPipe } from '@angular/common';
import { Component, inject } from '@angular/core';

import { ToastService } from './toast.service';

@Component({
  selector: 'app-toast-host',
  imports: [AsyncPipe],
  template: `
    <div class="toast-host" aria-live="polite" aria-relevant="additions">
      @for (toast of toasts.messages$ | async; track toast.id) {
        <div
          class="toast"
          [class.toast-success]="toast.kind === 'success'"
          [class.toast-error]="toast.kind === 'error'"
        >
          <span>{{ toast.text }}</span>
          <button
            type="button"
            class="toast-dismiss"
            (click)="toasts.dismiss(toast.id)"
            aria-label="Cerrar"
          >
            ×
          </button>
        </div>
      }
    </div>
  `,
})
export class ToastHostComponent {
  readonly toasts = inject(ToastService);
}
