<?php

declare(strict_types=1);

namespace App\Application\GetSettings;

use App\Domain\Music\Contracts\SettingsRepository;
use App\Domain\Music\ValueObjects\AudioFormat;

final readonly class GetSettingsHandler
{
    public function __construct(
        private SettingsRepository $settings,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        $stored = $this->settings->all();

        $musicPath = $stored['music_path'] ?? config('music.path');
        $defaultFormat = $stored['default_format'] ?? config('music.default_format');
        $maxConcurrency = $stored['max_concurrency'] ?? (string) config('music.max_concurrency');
        $cookiesPath = $stored['cookies_path'] ?? config('music.cookies_path');

        $format = AudioFormat::tryFrom((string) $defaultFormat) ?? AudioFormat::Mp3_320;

        return [
            'music_path' => is_string($musicPath) ? $musicPath : (string) config('music.path'),
            'default_format' => $format->value,
            'max_concurrency' => max(1, (int) $maxConcurrency),
            'cookies_path' => is_string($cookiesPath) && $cookiesPath !== '' ? $cookiesPath : null,
            'cookies_configured' => $this->cookiesConfigured($cookiesPath),
        ];
    }

    private function cookiesConfigured(mixed $cookiesPath): bool
    {
        if (! is_string($cookiesPath) || $cookiesPath === '') {
            return false;
        }

        return is_file($cookiesPath) && is_readable($cookiesPath);
    }
}
