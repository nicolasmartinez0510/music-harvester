<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Auth\AccountAuth;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class AuthController extends Controller
{
    public function avatars(): JsonResponse
    {
        return response()->json(['data' => config('avatars.ids')]);
    }

    public function options(AccountAuth $auth): JsonResponse
    {
        return response()->json([
            'data' => [
                'email_verification_enabled' => $auth->emailVerificationEnabled(),
            ],
        ]);
    }

    public function register(Request $request, AccountAuth $auth): JsonResponse
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'username' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9_-]{2,31}$/', 'unique:users,username'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
            'avatar_id' => ['required', 'string', Rule::in(config('avatars.ids'))],
        ]);

        $user = $auth->register($data);

        return response()->json([
            'data' => [
                'email' => $user->email,
                'verification_required' => $user->email_verified_at === null,
            ],
        ], 201);
    }

    public function verifyEmail(Request $request, AccountAuth $auth): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'digits:4'],
        ]);

        $auth->verifyEmail($data['email'], $data['code']);

        return response()->json(['data' => ['verified' => true]]);
    }

    public function resendVerification(Request $request, AccountAuth $auth): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $email = strtolower($data['email']);
        $user = User::query()->where('email', $email)->first();

        if ($auth->emailVerificationEnabled() && $user !== null && $user->email_verified_at === null) {
            $auth->issueVerificationCode($email);
        }

        return response()->json(['data' => ['sent' => true]]);
    }

    public function login(Request $request, AccountAuth $auth): UserResource
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        return new UserResource($auth->login($data));
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['data' => ['ok' => true]]);
    }

    public function me(Request $request): UserResource
    {
        /** @var User $user */
        $user = $request->user();

        return new UserResource($user);
    }

    public function forgotPassword(Request $request, AccountAuth $auth): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $auth->sendPasswordReset($data['email']);

        return response()->json([
            'data' => ['message' => 'Si el correo está registrado, enviamos un enlace para recuperar la contraseña.'],
        ]);
    }

    public function validatePasswordReset(Request $request, AccountAuth $auth): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'digits:8'],
        ]);

        if (! $auth->passwordResetIsValid($data['code'])) {
            return response()->json(['message' => 'Código inválido o vencido.'], 422);
        }

        return response()->json(['data' => ['valid' => true]]);
    }

    public function resetPassword(Request $request, AccountAuth $auth): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'digits:8'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $auth->resetPassword($data['code'], $data['password']);

        return response()->json(['data' => ['reset' => true]]);
    }
}
