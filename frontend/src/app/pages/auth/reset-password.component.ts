import { Component, inject, OnInit } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { passwordsMatch } from '../../core/password-match';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';

import { AuthService } from '../../core/auth.service';
import { ApiValidationError } from '../../core/models';

@Component({
  selector: 'app-reset-password',
  imports: [ReactiveFormsModule, RouterLink],
  templateUrl: './reset-password.component.html',
  styleUrl: './auth-page.css',
})
export class ResetPasswordComponent implements OnInit {
  private readonly auth = inject(AuthService);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly fb = inject(FormBuilder);

  code = '';
  checking = true;
  invalid = false;
  saving = false;
  errorMessage: string | null = null;

  readonly form = this.fb.nonNullable.group({
    password: ['', [Validators.required, Validators.minLength(8)]],
    password_confirmation: ['', Validators.required],
  }, { validators: [passwordsMatch] });

  ngOnInit(): void {
    this.auth.loadOptions().subscribe((enabled) => {
      if (!enabled) {
        void this.router.navigate(['/login']);
      }
    });

    this.code = this.route.snapshot.queryParamMap.get('code') ?? '';
    if (!/^\d{8}$/.test(this.code)) {
      this.fail();
      return;
    }

    this.auth.validateResetCode(this.code).subscribe((valid) => {
      this.checking = false;
      if (!valid) {
        this.fail();
      }
    });
  }

  submit(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }

    const raw = this.form.getRawValue();
    this.saving = true;
    this.auth.resetPassword(this.code, raw.password, raw.password_confirmation).subscribe({
      next: () => {
        void this.router.navigate(['/login'], { queryParams: { reset: '1' } });
      },
      error: (error: { error?: ApiValidationError }) => {
        this.saving = false;
        this.errorMessage = error.error?.errors?.['code']?.[0] ?? 'No se pudo cambiar la contraseña.';
      },
    });
  }

  private fail(): void {
    this.invalid = true;
    this.checking = false;
    void this.router.navigate(['/login'], { queryParams: { reset: 'invalid' } });
  }
}
