<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Music\Contracts\SettingsRepository;
use Illuminate\Support\Facades\DB;

final class EloquentSettingsRepository implements SettingsRepository
{
    /** @var list<string> */
    public const KEYS = [
        'music_path',
        'default_format',
        'max_concurrency',
        'cookies_path',
    ];

    public function get(string $key, ?string $default = null): ?string
    {
        $row = DB::table('settings')->where('key', $key)->first();

        if ($row === null) {
            return $default;
        }

        return $row->value;
    }

    public function set(string $key, ?string $value): void
    {
        $exists = DB::table('settings')->where('key', $key)->exists();

        if ($exists) {
            DB::table('settings')->where('key', $key)->update([
                'value' => $value,
                'updated_at' => now(),
            ]);

            return;
        }

        DB::table('settings')->insert([
            'key' => $key,
            'value' => $value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function all(): array
    {
        $stored = DB::table('settings')
            ->whereIn('key', self::KEYS)
            ->pluck('value', 'key')
            ->all();

        $settings = [];

        foreach (self::KEYS as $key) {
            $settings[$key] = array_key_exists($key, $stored) ? $stored[$key] : null;
        }

        return $settings;
    }
}
