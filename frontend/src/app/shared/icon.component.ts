import { Component, Input } from '@angular/core';

export type IconName =
  | 'plus'
  | 'refresh'
  | 'eye'
  | 'trash'
  | 'gear'
  | 'spinner'
  | 'x'
  | 'download'
  | 'list'
  | 'compass'
  | 'sun'
  | 'moon'
  | 'menu'
  | 'panel-left'
  | 'panel-right'
  | 'chevrons-left'
  | 'chevrons-right'
  | 'chevron-down'
  | 'star'
  | 'star-filled'
  | 'check'
  | 'provider-deezer'
  | 'provider-youtube-music';

@Component({
  selector: 'app-icon',
  template: `
    @switch (name) {
      @case ('plus') {
        <svg [attr.width]="size" [attr.height]="size" viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
        </svg>
      }
      @case ('refresh') {
        <svg [attr.width]="size" [attr.height]="size" viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <path
            d="M21 12a9 9 0 1 1-2.64-6.36M21 3v6h-6"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
          />
        </svg>
      }
      @case ('eye') {
        <svg [attr.width]="size" [attr.height]="size" viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <path
            d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"
            stroke="currentColor"
            stroke-width="2"
            stroke-linejoin="round"
          />
          <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2" />
        </svg>
      }
      @case ('trash') {
        <svg [attr.width]="size" [attr.height]="size" viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <path
            d="M4 7h16M10 11v6M14 11v6M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12M9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
          />
        </svg>
      }
      @case ('gear') {
        <svg [attr.width]="size" [attr.height]="size" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
          <path
            d="M19.14 12.94c.04-.31.06-.63.06-.94 0-.31-.02-.63-.06-.94l2.03-1.58c.18-.14.23-.41.12-.61l-1.92-3.32a.5.5 0 0 0-.59-.22l-2.39.96a7.4 7.4 0 0 0-1.62-.94l-.36-2.54A.49.49 0 0 0 13.91 2h-3.82a.49.49 0 0 0-.49.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96a.49.49 0 0 0-.59.22L2.72 8.87a.49.49 0 0 0 .12.61l2.03 1.58c-.04.31-.06.63-.06.94s.02.63.06.94L2.84 14.52a.49.49 0 0 0-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.25.41.49.41h3.82c.24 0 .44-.17.49-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32a.49.49 0 0 0-.12-.61l-2.01-1.58ZM12 15.6A3.6 3.6 0 1 1 12 8.4a3.6 3.6 0 0 1 0 7.2Z"
          />
        </svg>
      }
      @case ('spinner') {
        <svg class="icon-spin" [attr.width]="size" [attr.height]="size" viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2" opacity="0.25" />
          <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
        </svg>
      }
      @case ('x') {
        <svg [attr.width]="size" [attr.height]="size" viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <path d="M6 6l12 12M18 6 6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
        </svg>
      }
      @case ('download') {
        <svg [attr.width]="size" [attr.height]="size" viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <path
            d="M12 3v12m0 0 4-4m-4 4-4-4M5 19h14"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
          />
        </svg>
      }
      @case ('list') {
        <svg [attr.width]="size" [attr.height]="size" viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <path
            d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
          />
        </svg>
      }
      @case ('compass') {
        <svg [attr.width]="size" [attr.height]="size" viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2" />
          <path
            d="m14.5 9.5-2 5-5 2 2-5 5-2Z"
            stroke="currentColor"
            stroke-width="2"
            stroke-linejoin="round"
          />
        </svg>
      }
      @case ('sun') {
        <svg [attr.width]="size" [attr.height]="size" viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <circle cx="12" cy="12" r="4" stroke="currentColor" stroke-width="2" />
          <path
            d="M12 2v2m0 16v2M4.93 4.93l1.41 1.41m11.32 11.32 1.41 1.41M2 12h2m16 0h2M4.93 19.07l1.41-1.41m11.32-11.32 1.41-1.41"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
          />
        </svg>
      }
      @case ('moon') {
        <svg [attr.width]="size" [attr.height]="size" viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <path
            d="M21 14.5A8.5 8.5 0 1 1 9.5 3a7 7 0 0 0 11.5 11.5Z"
            stroke="currentColor"
            stroke-width="2"
            stroke-linejoin="round"
          />
        </svg>
      }
      @case ('menu') {
        <svg [attr.width]="size" [attr.height]="size" viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <path d="M4 6h16M4 12h16M4 18h16" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
        </svg>
      }
      @case ('panel-left') {
        <svg [attr.width]="size" [attr.height]="size" viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <rect x="3" y="4" width="18" height="16" rx="2" stroke="currentColor" stroke-width="2" />
          <path d="M9 4v16" stroke="currentColor" stroke-width="2" />
          <path
            d="M14.5 9.5 12 12l2.5 2.5"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
          />
        </svg>
      }
      @case ('panel-right') {
        <svg [attr.width]="size" [attr.height]="size" viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <rect x="3" y="4" width="18" height="16" rx="2" stroke="currentColor" stroke-width="2" />
          <path d="M9 4v16" stroke="currentColor" stroke-width="2" />
          <path
            d="M12 9.5 14.5 12 12 14.5"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
          />
        </svg>
      }
      @case ('chevrons-left') {
        <svg [attr.width]="size" [attr.height]="size" viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <path
            d="m11 7-5 5 5 5M18 7l-5 5 5 5"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
          />
        </svg>
      }
      @case ('chevrons-right') {
        <svg [attr.width]="size" [attr.height]="size" viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <path
            d="m13 7 5 5-5 5M6 7l5 5-5 5"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
          />
        </svg>
      }
      @case ('chevron-down') {
        <svg [attr.width]="size" [attr.height]="size" viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <path
            d="m6 9 6 6 6-6"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
          />
        </svg>
      }
      @case ('star') {
        <svg [attr.width]="size" [attr.height]="size" viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <path
            d="m12 3.5 2.6 5.3 5.8.8-4.2 4.1 1 5.8L12 16.7 6.8 19.5l1-5.8L3.6 9.6l5.8-.8L12 3.5Z"
            stroke="currentColor"
            stroke-width="2"
            stroke-linejoin="round"
          />
        </svg>
      }
      @case ('star-filled') {
        <svg [attr.width]="size" [attr.height]="size" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
          <path d="m12 3.5 2.6 5.3 5.8.8-4.2 4.1 1 5.8L12 16.7 6.8 19.5l1-5.8L3.6 9.6l5.8-.8L12 3.5Z" />
        </svg>
      }
      @case ('check') {
        <svg [attr.width]="size" [attr.height]="size" viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <path
            d="M5 12.5 10 17.5 19 7"
            stroke="currentColor"
            stroke-width="2.25"
            stroke-linecap="round"
            stroke-linejoin="round"
          />
        </svg>
      }
      @case ('provider-deezer') {
        <svg [attr.width]="size" [attr.height]="size" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
          <rect x="3" y="14" width="3.5" height="6" rx="1" />
          <rect x="8" y="10" width="3.5" height="10" rx="1" />
          <rect x="13" y="6" width="3.5" height="14" rx="1" />
          <rect x="18" y="3" width="3.5" height="17" rx="1" />
        </svg>
      }
      @case ('provider-youtube-music') {
        <svg [attr.width]="size" [attr.height]="size" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
          <circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2" />
          <path d="M10 8.5v7l6-3.5-6-3.5Z" />
        </svg>
      }
    }
  `,
  styles: `
    :host {
      display: inline-flex;
      line-height: 0;
      vertical-align: middle;
    }

    .icon-spin {
      animation: icon-spin 0.8s linear infinite;
    }

    @keyframes icon-spin {
      to {
        transform: rotate(360deg);
      }
    }
  `,
})
export class IconComponent {
  @Input({ required: true }) name!: IconName;
  @Input() size = 16;
}
