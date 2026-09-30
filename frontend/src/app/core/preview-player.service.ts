import { Injectable, signal } from '@angular/core';

@Injectable({ providedIn: 'root' })
export class PreviewPlayerService {
  private readonly audio = typeof Audio === 'undefined' ? null : new Audio();
  private currentUrl: string | null = null;
  private progressFrame = 0;

  /** URL currently playing. Null while paused, loading, or idle. */
  readonly playingUrl = signal<string | null>(null);

  /** URL waiting for enough data to play. */
  readonly loadingUrl = signal<string | null>(null);

  /** URL of the clip loaded in the player, including while paused. */
  readonly activeUrl = signal<string | null>(null);

  /** Playback position of the active clip, from 0 to 1. */
  readonly progress = signal(0);

  constructor() {
    const audio = this.audio;
    if (!audio) {
      return;
    }

    audio.preload = 'none';
    audio.addEventListener('playing', () => this.markPlaying());
    audio.addEventListener('waiting', () => this.markWaiting());
    audio.addEventListener('pause', () => this.markPaused());
    audio.addEventListener('ended', () => this.clear());
    audio.addEventListener('error', () => this.clear());
  }

  toggle(url: string): void {
    const audio = this.audio;
    if (!audio || url === '') {
      return;
    }

    if (this.currentUrl === url) {
      if (audio.paused) {
        this.loadingUrl.set(url);
        void audio.play().then(
          () => this.markPlaying(),
          () => this.clear(),
        );
      } else {
        audio.pause();
      }
      return;
    }

    audio.pause();
    this.progress.set(0);
    this.playingUrl.set(null);
    this.loadingUrl.set(url);
    this.activeUrl.set(url);
    this.currentUrl = url;
    audio.src = url;
    void audio.play().then(
      () => this.markPlaying(),
      () => this.clear(),
    );
  }

  stop(): void {
    this.audio?.pause();
    this.clear();
  }

  private markPlaying(): void {
    const url = this.currentUrl;
    const audio = this.audio;
    if (!url || !audio || audio.paused) {
      return;
    }

    this.loadingUrl.set(null);
    this.playingUrl.set(url);
    this.activeUrl.set(url);
    this.startProgressLoop();
  }

  private markWaiting(): void {
    if (this.currentUrl && this.audio && !this.audio.paused) {
      this.loadingUrl.set(this.currentUrl);
    }
  }

  private markPaused(): void {
    this.stopProgressLoop();
    this.loadingUrl.set(null);
    this.playingUrl.set(null);
  }

  private startProgressLoop(): void {
    this.stopProgressLoop();
    const tick = (): void => {
      const audio = this.audio;
      if (!audio || audio.paused) {
        return;
      }

      const duration = audio.duration;
      if (Number.isFinite(duration) && duration > 0) {
        this.progress.set(Math.min(1, audio.currentTime / duration));
      }

      this.progressFrame = requestAnimationFrame(tick);
    };

    this.progressFrame = requestAnimationFrame(tick);
  }

  private stopProgressLoop(): void {
    if (this.progressFrame !== 0) {
      cancelAnimationFrame(this.progressFrame);
      this.progressFrame = 0;
    }
  }

  private clear(): void {
    this.stopProgressLoop();
    this.playingUrl.set(null);
    this.loadingUrl.set(null);
    this.activeUrl.set(null);
    this.progress.set(0);
    this.currentUrl = null;
  }
}
