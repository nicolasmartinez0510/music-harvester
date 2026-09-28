import { Component, inject, OnInit } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';

import { AuthService } from '../../core/auth.service';
import { ApiValidationError } from '../../core/models';

@Component({
  selector: 'app-verify-email',
  imports: [ReactiveFormsModule, RouterLink],
  templateUrl: './verify-email.component.html',
  styleUrl: './auth-page.css',
})
export class VerifyEmailComponent implements OnInit {
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);
  private readonly fb = inject(FormBuilder);

  email = '';
  saving = false;
  message: string | null = null;
  errorMessage: string | null = null;

  readonly form = this.fb.nonNullable.group({
    code: ['', [Validators.required, Validators.pattern(/^\d{4}$/)]],
  });

  ngOnInit(): void {
    this.email = sessionStorage.getItem('mh-verify-email') ?? '';
    if (!this.email) {
      void this.router.navigate(['/register']);
    }
  }

  submit(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }

    this.saving = true;
    this.errorMessage = null;
    this.auth.verifyEmail(this.email, this.form.controls.code.value).subscribe({
      next: () => {
        sessionStorage.removeItem('mh-verify-email');
        void this.router.navigate(['/login'], { queryParams: { verified: '1' } });
      },
      error: (error: { error?: ApiValidationError }) => {
        this.saving = false;
        this.errorMessage = error.error?.errors?.['code']?.[0] ?? 'El código no es válido.';
      },
    });
  }

  resend(): void {
    this.message = null;
    this.errorMessage = null;
    this.auth.resendVerification(this.email).subscribe({
      next: () => {
        this.message = 'Si la cuenta sigue sin confirmar, reenviamos el código.';
      },
      error: () => {
        this.errorMessage = 'No se pudo reenviar el código.';
      },
    });
  }
}
