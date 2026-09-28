import { Component, inject, OnInit } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';

import { AuthService } from '../../core/auth.service';
import { passwordsMatch } from '../../core/password-match';
import { ApiValidationError } from '../../core/models';

@Component({
  selector: 'app-register',
  imports: [ReactiveFormsModule, RouterLink],
  templateUrl: './register.component.html',
  styleUrl: './auth-page.css',
})
export class RegisterComponent implements OnInit {
  readonly auth = inject(AuthService);
  private readonly router = inject(Router);
  private readonly fb = inject(FormBuilder);

  avatars: string[] = [];
  saving = false;
  errorMessage: string | null = null;

  readonly form = this.fb.nonNullable.group({
    first_name: ['', Validators.required],
    last_name: ['', Validators.required],
    username: ['', [Validators.required, Validators.pattern(/^[a-z0-9][a-z0-9_-]{2,31}$/)]],
    email: ['', [Validators.required, Validators.email]],
    password: ['', [Validators.required, Validators.minLength(8)]],
    password_confirmation: ['', Validators.required],
    avatar_id: ['meme-01', Validators.required],
  }, { validators: [passwordsMatch] });

  ngOnInit(): void {
    this.auth.avatars().subscribe({
      next: (ids) => {
        this.avatars = ids;
        if (ids[0]) {
          this.form.controls.avatar_id.setValue(ids[0]);
        }
      },
    });
  }

  submit(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }

    const raw = this.form.getRawValue();
    this.saving = true;
    this.errorMessage = null;
    this.auth.register(raw).subscribe({
      next: (result) => {
        if (!result.verification_required) {
          void this.router.navigate(['/login'], { queryParams: { registered: '1' } });
          return;
        }
        sessionStorage.setItem('mh-verify-email', result.email);
        void this.router.navigate(['/verify-email']);
      },
      error: (error: { error?: ApiValidationError }) => {
        this.saving = false;
        const body = error.error;
        this.errorMessage =
          body?.message ?? Object.values(body?.errors ?? {})[0]?.[0] ?? 'No se pudo crear la cuenta.';
      },
    });
  }
}
