import { Component, inject, OnInit } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';

import { AuthService } from '../../core/auth.service';

@Component({
  selector: 'app-forgot-password',
  imports: [ReactiveFormsModule, RouterLink],
  templateUrl: './forgot-password.component.html',
  styleUrl: './auth-page.css',
})
export class ForgotPasswordComponent implements OnInit {
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);
  private readonly fb = inject(FormBuilder);

  saving = false;
  sent = false;

  readonly form = this.fb.nonNullable.group({
    email: ['', [Validators.required, Validators.email]],
  });

  ngOnInit(): void {
    this.auth.loadOptions().subscribe((enabled) => {
      if (!enabled) {
        void this.router.navigate(['/login']);
      }
    });
  }

  submit(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }

    this.saving = true;
    this.auth.forgotPassword(this.form.controls.email.value).subscribe({
      next: () => {
        this.saving = false;
        this.sent = true;
      },
      error: (error: { status?: number }) => {
        this.saving = false;
        if (error.status === 422) {
          void this.router.navigate(['/login']);
          return;
        }
        this.sent = true;
      },
    });
  }
}
