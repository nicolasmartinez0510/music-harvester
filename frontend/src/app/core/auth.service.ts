import { HttpClient } from '@angular/common/http';
import { Injectable, inject, signal } from '@angular/core';
import { Observable, catchError, map, of, tap } from 'rxjs';

import { ApiResource, AuthUser } from './models';

export interface RegisterPayload {
  first_name: string;
  last_name: string;
  username: string;
  email: string;
  password: string;
  password_confirmation: string;
  avatar_id: string;
}

@Injectable({ providedIn: 'root' })
export class AuthService {
  private readonly http = inject(HttpClient);
  private loaded = false;

  readonly user = signal<AuthUser | null>(null);
  readonly ready = signal(false);
  readonly emailVerificationEnabled = signal(true);

  bootstrap(): Observable<AuthUser | null> {
    this.loadOptions().subscribe();

    if (this.loaded) {
      return of(this.user());
    }

    return this.http.get<ApiResource<AuthUser>>('/api/auth/me', { withCredentials: true }).pipe(
      map((response) => response.data),
      tap((user) => {
        this.user.set(user);
        this.loaded = true;
        this.ready.set(true);
      }),
      catchError(() => {
        this.user.set(null);
        this.loaded = true;
        this.ready.set(true);
        return of(null);
      }),
    );
  }

  loadOptions(): Observable<boolean> {
    return this.http.get<ApiResource<{ email_verification_enabled: boolean }>>('/api/auth/options').pipe(
      map((response) => response.data.email_verification_enabled),
      tap((enabled) => this.emailVerificationEnabled.set(enabled)),
      catchError(() => of(this.emailVerificationEnabled())),
    );
  }

  avatars(): Observable<string[]> {
    return this.http
      .get<ApiResource<string[]>>('/api/auth/avatars')
      .pipe(map((response) => response.data));
  }

  register(payload: RegisterPayload): Observable<{ email: string; verification_required: boolean }> {
    return this.http
      .post<ApiResource<{ email: string; verification_required: boolean }>>('/api/auth/register', payload)
      .pipe(map((response) => response.data));
  }

  verifyEmail(email: string, code: string): Observable<void> {
    return this.http.post<unknown>('/api/auth/verify-email', { email, code }).pipe(map(() => undefined));
  }

  resendVerification(email: string): Observable<void> {
    return this.http
      .post<unknown>('/api/auth/resend-verification', { email })
      .pipe(map(() => undefined));
  }

  login(email: string, password: string): Observable<AuthUser> {
    return this.http.post<ApiResource<AuthUser>>('/api/auth/login', { email, password }).pipe(
      map((response) => response.data),
      tap((user) => {
        this.user.set(user);
        this.loaded = true;
        this.ready.set(true);
      }),
    );
  }

  logout(): Observable<void> {
    return this.http.post<unknown>('/api/auth/logout', {}).pipe(
      map(() => undefined),
      tap(() => {
        this.user.set(null);
        this.loaded = true;
      }),
    );
  }

  forgotPassword(email: string): Observable<void> {
    return this.http.post<unknown>('/api/auth/forgot-password', { email }).pipe(map(() => undefined));
  }

  validateResetCode(code: string): Observable<boolean> {
    return this.http
      .get<ApiResource<{ valid: boolean }>>('/api/auth/password-reset/validate', { params: { code } })
      .pipe(
        map(() => true),
        catchError(() => of(false)),
      );
  }

  resetPassword(code: string, password: string, passwordConfirmation: string): Observable<void> {
    return this.http
      .post<unknown>('/api/auth/reset-password', {
        code,
        password,
        password_confirmation: passwordConfirmation,
      })
      .pipe(map(() => undefined));
  }

  setUser(user: AuthUser): void {
    this.user.set(user);
    this.loaded = true;
    this.ready.set(true);
  }
}
