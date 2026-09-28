import { AbstractControl, ValidationErrors } from '@angular/forms';

export function passwordsMatch(group: AbstractControl): ValidationErrors | null {
  const password = String(group.get('password')?.value ?? '');
  const confirmation = group.get('password_confirmation');
  const confirmValue = String(confirmation?.value ?? '');

  if (confirmValue === '' && !confirmation?.dirty) {
    return null;
  }

  if (password === '' && confirmValue === '') {
    return null;
  }

  return password === confirmValue ? null : { passwordMismatch: true };
}
