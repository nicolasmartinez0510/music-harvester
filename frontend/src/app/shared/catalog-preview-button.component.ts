import { Component, computed, inject, input } from '@angular/core';

import { PreviewPlayerService } from '../core/preview-player.service';
import { IconComponent } from './icon.component';

const RING_RADIUS = 15.5;
const RING_LENGTH = 2 * Math.PI * RING_RADIUS;

@Component({
  selector: 'app-catalog-preview-btn',
  imports: [IconComponent],
  host: {
    '[class.has-preview]': '!!previewUrl()',
    '[class.is-playing]': 'playing() || loading()',
  },
  template: `
    @if (previewUrl()) {
      <button
        type="button"
        class="preview-btn"
        (click)="onClick($event)"
        [attr.title]="label()"
        [attr.aria-label]="label()"
        [attr.aria-pressed]="playing()"
        [attr.aria-busy]="loading()"
      >
        <span class="preview-mark">
          @if (showRing()) {
            <svg class="progress-ring" viewBox="0 0 36 36" aria-hidden="true">
              <circle class="ring-track" cx="18" cy="18" r="15.5" />
              <circle
                class="ring-value"
                cx="18"
                cy="18"
                r="15.5"
                [attr.stroke-dasharray]="ringLength"
                [attr.stroke-dashoffset]="dashOffset()"
              />
            </svg>
          }
          @if (loading()) {
            <app-icon name="spinner" [size]="18" />
          } @else {
            <app-icon [name]="playing() ? 'pause' : 'play'" [size]="16" />
          }
        </span>
      </button>
    }
  `,
  styles: `
    :host {
      position: absolute;
      inset: 0;
      display: grid;
      place-items: center;
      border-radius: inherit;
      pointer-events: none;
      opacity: 0;
      background: rgb(0 0 0 / 42%);
      transition: opacity 0.15s ease;
    }

    :host-context(.hit:hover).has-preview,
    :host.has-preview.is-playing {
      opacity: 1;
      pointer-events: auto;
    }

    @media (hover: none), (max-width: 640px) {
      :host.has-preview {
        opacity: 1;
        pointer-events: auto;
        background: rgb(0 0 0 / 34%);
      }
    }

    .preview-btn {
      display: grid;
      place-items: center;
      width: 100%;
      height: 100%;
      margin: 0;
      padding: 0;
      border: 0;
      border-radius: inherit;
      background: transparent;
      color: #fff;
      cursor: pointer;
      filter: drop-shadow(0 1px 2px rgb(0 0 0 / 55%));
    }

    .preview-mark {
      position: relative;
      display: grid;
      place-items: center;
      width: 1.85rem;
      height: 1.85rem;
    }

    .progress-ring {
      position: absolute;
      inset: 0;
      width: 100%;
      height: 100%;
      transform: rotate(-90deg);
    }

    .ring-track,
    .ring-value {
      fill: none;
      stroke-width: 2.25;
    }

    .ring-track {
      stroke: rgb(255 255 255 / 35%);
    }

    .ring-value {
      stroke: #fff;
      stroke-linecap: round;
    }
  `,
})
export class CatalogPreviewButtonComponent {
  private readonly player = inject(PreviewPlayerService);

  readonly ringLength = RING_LENGTH;
  readonly previewUrl = input<string | null | undefined>();

  readonly playing = computed(() => {
    const url = this.previewUrl();
    return !!url && this.player.playingUrl() === url;
  });

  readonly loading = computed(() => {
    const url = this.previewUrl();
    return !!url && this.player.loadingUrl() === url;
  });

  readonly showRing = computed(() => {
    const url = this.previewUrl();
    if (!url || this.player.activeUrl() !== url) {
      return false;
    }

    return this.loading() || this.playing() || this.player.progress() > 0;
  });

  readonly dashOffset = computed(() => RING_LENGTH * (1 - (this.showRing() ? this.player.progress() : 0)));

  readonly label = computed(() => {
    if (this.loading()) {
      return 'Cargando preview';
    }
    if (this.playing()) {
      return 'Pausar';
    }
    return 'Escuchar preview';
  });

  onClick(event: Event): void {
    event.preventDefault();
    event.stopPropagation();
    const url = this.previewUrl();
    if (url) {
      this.player.toggle(url);
    }
  }
}
