<?php

declare(strict_types=1);

namespace App\Application\Auth;

use App\Domain\Music\Contracts\SettingsRepository;
use App\Mail\PasswordResetMail;
use App\Mail\VerificationCodeMail;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class AccountAuth
{
    public function __construct(
        private readonly SettingsRepository $settings,
    ) {}

    /**
     * @param  array{first_name: string, last_name: string, username: string, email: string, password: string, avatar_id: string}  $input
     */
    public function register(array $input): User
    {
        $user = User::query()->create([
            'first_name' => $input['first_name'],
            'last_name' => $input['last_name'],
            'name' => trim($input['first_name'].' '.$input['last_name']),
            'username' => strtolower($input['username']),
            'email' => strtolower($input['email']),
            'password' => $input['password'],
            'avatar_id' => $input['avatar_id'],
            'role' => 'user',
            'server_storage_status' => 'none',
            'download_destination' => 'direct',
        ]);

        if (! $this->emailVerificationEnabled()) {
            $user->forceFill(['email_verified_at' => now()])->save();

            return $user;
        }

        $this->issueVerificationCode($user->email);

        return $user;
    }

    public function issueVerificationCode(string $email): void
    {
        $code = $this->numericCode(4);
        DB::table('email_verification_codes')->where('email', $email)->delete();
        DB::table('email_verification_codes')->insert([
            'email' => $email,
            'code_hash' => hash('sha256', $code),
            'expires_at' => now()->addMinutes(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Mail::to($email)->send(new VerificationCodeMail($code));
    }

    public function verifyEmail(string $email, string $code): void
    {
        $email = strtolower($email);
        $row = DB::table('email_verification_codes')->where('email', $email)->first();

        if ($row === null || ! hash_equals((string) $row->code_hash, hash('sha256', $code)) || now()->greaterThan($row->expires_at)) {
            throw ValidationException::withMessages([
                'code' => 'El código es inválido o venció.',
            ]);
        }

        $user = User::query()->where('email', $email)->first();
        if ($user === null) {
            throw ValidationException::withMessages([
                'email' => 'No hay una cuenta con ese correo.',
            ]);
        }

        $user->forceFill(['email_verified_at' => now()])->save();
        DB::table('email_verification_codes')->where('email', $email)->delete();
    }

    /**
     * @param  array{email: string, password: string}  $input
     */
    public function login(array $input): User
    {
        $email = strtolower($input['email']);
        $user = User::query()->where('email', $email)->first();

        if ($user === null || ! Hash::check($input['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => 'Credenciales incorrectas.',
            ]);
        }

        if (! $this->emailVerificationEnabled()) {
            if ($user->email_verified_at === null) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }
        } elseif ($user->email_verified_at === null) {
            throw ValidationException::withMessages([
                'email' => 'Tenés que confirmar el correo antes de entrar.',
            ])->status(403);
        }

        Auth::login($user);
        request()->session()->regenerate();

        return $user;
    }

    public function sendPasswordReset(string $email): void
    {
        $this->ensurePasswordResetEnabled();

        $email = strtolower($email);
        $user = User::query()->where('email', $email)->whereNotNull('email_verified_at')->first();

        if ($user === null) {
            return;
        }

        $code = $this->numericCode(8);
        DB::table('password_reset_codes')->where('email', $email)->delete();
        DB::table('password_reset_codes')->insert([
            'email' => $email,
            'code_hash' => hash('sha256', $code),
            'expires_at' => now()->addMinutes(15),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $url = rtrim((string) config('app.url'), '/').'/reset-password?code='.$code;
        Mail::to($email)->send(new PasswordResetMail($url));
    }

    public function passwordResetIsValid(string $code): bool
    {
        if (! $this->emailVerificationEnabled()) {
            return false;
        }

        $row = DB::table('password_reset_codes')->where('code_hash', hash('sha256', $code))->first();

        return $row !== null && now()->lessThanOrEqualTo($row->expires_at);
    }

    public function resetPassword(string $code, string $password): void
    {
        $this->ensurePasswordResetEnabled();

        $row = DB::table('password_reset_codes')->where('code_hash', hash('sha256', $code))->first();

        if ($row === null || now()->greaterThan($row->expires_at)) {
            throw ValidationException::withMessages([
                'code' => 'El código es inválido o venció.',
            ]);
        }

        $user = User::query()->where('email', $row->email)->first();
        if ($user === null) {
            throw ValidationException::withMessages([
                'code' => 'El código es inválido o venció.',
            ]);
        }

        $user->forceFill([
            'password' => $password,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        DB::table('password_reset_codes')->where('email', $row->email)->delete();
    }

    public function emailVerificationEnabled(): bool
    {
        if (! Schema::hasTable('settings')) {
            return true;
        }

        $value = $this->settings->get('email_verification_enabled');
        if ($value === null || $value === '') {
            return true;
        }

        return ! in_array(strtolower($value), ['0', 'false', 'off'], true);
    }

    private function ensurePasswordResetEnabled(): void
    {
        if ($this->emailVerificationEnabled()) {
            return;
        }

        throw ValidationException::withMessages([
            'email' => 'La recuperación de contraseña está deshabilitada.',
        ]);
    }

    private function numericCode(int $digits): string
    {
        $max = (10 ** $digits) - 1;

        return str_pad((string) random_int(0, $max), $digits, '0', STR_PAD_LEFT);
    }
}
