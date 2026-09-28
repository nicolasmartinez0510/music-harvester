<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth;

use Illuminate\Support\Facades\DB;

final class UserCredentialStore
{
    public function arl(int $userId): ?string
    {
        $value = DB::table('user_provider_credentials')
            ->where('user_id', $userId)
            ->where('provider', 'deezer')
            ->value('arl');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function cookiesPath(int $userId): ?string
    {
        $value = DB::table('user_provider_credentials')
            ->where('user_id', $userId)
            ->where('provider', 'youtube_music')
            ->value('cookies_path');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function setArl(int $userId, ?string $arl): void
    {
        $this->upsert($userId, 'deezer', ['arl' => $arl]);
    }

    public function setCookiesPath(int $userId, ?string $path): void
    {
        $this->upsert($userId, 'youtube_music', ['cookies_path' => $path]);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function upsert(int $userId, string $provider, array $values): void
    {
        $exists = DB::table('user_provider_credentials')
            ->where('user_id', $userId)
            ->where('provider', $provider)
            ->exists();

        if ($exists) {
            DB::table('user_provider_credentials')
                ->where('user_id', $userId)
                ->where('provider', $provider)
                ->update([...$values, 'updated_at' => now()]);

            return;
        }

        DB::table('user_provider_credentials')->insert([
            'user_id' => $userId,
            'provider' => $provider,
            ...$values,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
