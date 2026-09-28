import { HttpInterceptorFn, HttpErrorResponse } from '@angular/common/http';
import { inject } from '@angular/core';
import { Router } from '@angular/router';
import { catchError, from, switchMap, throwError } from 'rxjs';

function hasXsrfCookie(): boolean {
  return document.cookie.split(';').some((part) => part.trim().startsWith('XSRF-TOKEN='));
}

export const authInterceptor: HttpInterceptorFn = (req, next) => {
  const router = inject(Router);
  const withCreds = req.clone({ withCredentials: true });
  const mutating = !['GET', 'HEAD', 'OPTIONS'].includes(req.method);
  const skipCsrf = req.url.includes('/sanctum/csrf-cookie');

  const send = (request: typeof withCreds) =>
    next(request).pipe(
      catchError((error: HttpErrorResponse) => {
        if (error.status === 401 && !request.url.includes('/api/auth/')) {
          void router.navigate(['/login']);
        }
        return throwError(() => error);
      }),
    );

  if (!mutating || skipCsrf || hasXsrfCookie()) {
    return send(withCreds);
  }

  return from(fetch('/sanctum/csrf-cookie', { credentials: 'include' })).pipe(
    switchMap(() => send(withCreds)),
  );
};
