import { Injectable } from '@angular/core';
import { BehaviorSubject } from 'rxjs';

export type ToastKind = 'success' | 'error';

export interface ToastMessage {
  id: number;
  kind: ToastKind;
  text: string;
}

@Injectable({ providedIn: 'root' })
export class ToastService {
  private readonly messagesSubject = new BehaviorSubject<ToastMessage[]>([]);
  private nextId = 1;

  readonly messages$ = this.messagesSubject.asObservable();

  success(text: string, durationMs = 3500): void {
    this.push('success', text, durationMs);
  }

  error(text: string, durationMs = 4500): void {
    this.push('error', text, durationMs);
  }

  dismiss(id: number): void {
    this.messagesSubject.next(this.messagesSubject.value.filter((m) => m.id !== id));
  }

  private push(kind: ToastKind, text: string, durationMs: number): void {
    const id = this.nextId++;
    this.messagesSubject.next([...this.messagesSubject.value, { id, kind, text }]);
    window.setTimeout(() => this.dismiss(id), durationMs);
  }
}
