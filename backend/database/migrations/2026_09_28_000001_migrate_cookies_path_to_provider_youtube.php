<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $legacy = DB::table('settings')->where('key', 'cookies_path')->first();

        if ($legacy === null) {
            return;
        }

        $exists = DB::table('settings')
            ->where('key', 'provider_youtube_music_cookies_path')
            ->exists();

        if ($exists) {
            return;
        }

        $value = $legacy->value;

        if ($value === null || $value === '') {
            return;
        }

        DB::table('settings')->insert([
            'key' => 'provider_youtube_music_cookies_path',
            'value' => $value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Keep migrated key; no-op.
    }
};
