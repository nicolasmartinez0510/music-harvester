<?php

declare(strict_types=1);

namespace App\Application\Settings;

use App\Domain\Music\Contracts\SettingsRepository;

/**
 * Resolves provider credentials from DB settings with config/env fallbacks.
 */
final readonly class ProviderSettingsResolver
{
    public function __construct(
        private SettingsRepository $settings,
    ) {}

    public function youtubeMusicCookiesPath(): ?string
    {
        $stored = $this->settings->get('provider_youtube_music_cookies_path')
            ?? $this->settings->get('cookies_path');

        if (is_string($stored) && $stored !== '') {
            return $stored;
        }

        $fromConfig = config('music.youtube_music_cookies_path') ?? config('music.cookies_path');

        return is_string($fromConfig) && $fromConfig !== '' ? $fromConfig : null;
    }

    public function deezerArl(): ?string
    {
        $stored = $this->settings->get('provider_deezer_arl');

        if (is_string($stored) && $stored !== '') {
            return $stored;
        }

        $fromConfig = config('music.deezer_arl');

        return is_string($fromConfig) && $fromConfig !== '' ? $fromConfig : null;
    }

    public function deezerMode(): string
    {
        $stored = $this->settings->get('provider_deezer_mode');

        if (is_string($stored) && in_array($stored, ['native', 'hybrid'], true)) {
            return $stored;
        }

        $fromConfig = (string) config('music.deezer_mode', 'native');

        return in_array($fromConfig, ['native', 'hybrid'], true) ? $fromConfig : 'native';
    }

    /**
     * @return list<string>
     */
    public function enabledProviders(): array
    {
        $stored = $this->settings->get('enabled_providers');
        $raw = is_string($stored) && $stored !== ''
            ? $stored
            : (string) config('music.enabled_providers', 'youtube_music,deezer');

        $names = array_values(array_filter(array_map(
            static fn (string $name): string => trim($name),
            explode(',', $raw),
        ), static fn (string $name): bool => $name !== ''));

        return $names !== [] ? $names : ['youtube_music', 'deezer'];
    }

    public function musicPath(): string
    {
        $stored = $this->settings->get('music_path');

        if (is_string($stored) && $stored !== '') {
            return $stored;
        }

        return (string) config('music.path');
    }

    public function isFileConfigured(?string $path): bool
    {
        return is_string($path) && $path !== '' && is_file($path) && is_readable($path);
    }

    public function isArlConfigured(?string $arl = null): bool
    {
        $value = $arl ?? $this->deezerArl();

        return is_string($value) && strlen($value) >= 32;
    }
}
