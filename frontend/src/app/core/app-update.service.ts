import { Injectable, inject } from '@angular/core';
import { SwUpdate } from '@angular/service-worker';

import { ToastService } from '../shared/toast.service';

@Injectable({ providedIn: 'root' })
export class AppUpdateService {
  private readonly updates = inject(SwUpdate);
  private readonly toast = inject(ToastService);
  private listening = false;

  listen(): void {
    if (this.listening || !this.updates.isEnabled) {
      return;
    }
    this.listening = true;
    this.updates.versionUpdates.subscribe((event) => {
      if (event.type !== 'VERSION_READY') {
        return;
      }
      this.toast.prompt('Hay una nueva versión.', 'Recargar', () => {
        void this.updates.activateUpdate().then(() => document.location.reload());
      });
    });
  }
}
