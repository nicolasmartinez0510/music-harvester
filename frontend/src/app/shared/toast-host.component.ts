import { AsyncPipe } from '@angular/common';
import { Component, inject } from '@angular/core';

import { ToastMessage, ToastService } from './toast.service';

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
          @if (toast.actionLabel) {
            <button type="button" class="toast-action" (click)="runAction(toast)">
              {{ toast.actionLabel }}
            </button>
          }
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

  runAction(toast: ToastMessage): void {
    toast.onAction?.();
    this.toasts.dismiss(toast.id);
  }
}
