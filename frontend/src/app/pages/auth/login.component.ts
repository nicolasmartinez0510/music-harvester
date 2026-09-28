import { Component, inject } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';

import { AuthService } from '../../core/auth.service';
import { ApiValidationError } from '../../core/models';

@Component({
  selector: 'app-login',
  imports: [ReactiveFormsModule, RouterLink],
  templateUrl: './login.component.html',
  styleUrl: './auth-page.css',
})
export class LoginComponent {
  readonly auth = inject(AuthService);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);
  private readonly fb = inject(FormBuilder);

  saving = false;
  errorMessage: string | null = null;
  registered = this.route.snapshot.queryParamMap.get('registered') === '1';

  readonly form = this.fb.nonNullable.group({
    email: ['', [Validators.required, Validators.email]],
    password: ['', Validators.required],
  });

  submit(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }

    this.saving = true;
    this.errorMessage = null;
    const { email, password } = this.form.getRawValue();
    this.auth.login(email, password).subscribe({
      next: () => {
        void this.router.navigate(['/downloads']);
      },
      error: (error: { status?: number; error?: ApiValidationError }) => {
        this.saving = false;
        if (error.status === 403) {
          sessionStorage.setItem('mh-verify-email', email);
          void this.router.navigate(['/verify-email']);
          return;
        }
        this.errorMessage = error.error?.message ?? 'No se pudo iniciar sesión.';
      },
    });
  }
}
