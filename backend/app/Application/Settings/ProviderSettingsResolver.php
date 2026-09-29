<?php

declare(strict_types=1);

namespace App\Application\Settings;

use App\Domain\Music\Contracts\SettingsRepository;
use App\Domain\Music\ValueObjects\AudioFormat;
use App\Infrastructure\Auth\UserCredentialStore;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

/**
 * Resolves provider credentials from the authenticated user, then legacy DB/env for admins.
 */
final readonly class ProviderSettingsResolver
{
    public function __construct(
        private SettingsRepository $settings,
        private ?UserCredentialStore $credentials = null,
    ) {}

    public function youtubeMusicCookiesPath(?int $userId = null): ?string
    {
        $resolvedId = $userId ?? $this->authenticatedUserId();

        if ($resolvedId !== null && $this->credentials !== null) {
            $personal = $this->credentials->cookiesPath($resolvedId);
            if (is_string($personal) && $personal !== '') {
                return $personal;
            }

            $user = User::query()->find($resolvedId);
            if ($user !== null && ! $user->isAdmin()) {
                return null;
            }
        }

        $stored = $this->setting('provider_youtube_music_cookies_path')
            ?? $this->setting('cookies_path');

        if (is_string($stored) && $stored !== '') {
            return $stored;
        }

        $fromConfig = config('music.youtube_music_cookies_path') ?? config('music.cookies_path');

        return is_string($fromConfig) && $fromConfig !== '' ? $fromConfig : null;
    }

    public function deezerArl(?int $userId = null): ?string
    {
        $resolvedId = $userId ?? $this->authenticatedUserId();

        if ($resolvedId !== null && $this->credentials !== null) {
            $personal = $this->credentials->arl($resolvedId);
            if (is_string($personal) && $personal !== '') {
                return $personal;
            }

            $user = User::query()->find($resolvedId);
            if ($user !== null && ! $user->isAdmin()) {
                return null;
            }
        }

        $stored = $this->setting('provider_deezer_arl');

        if (is_string($stored) && $stored !== '') {
            return $stored;
        }

        $fromConfig = config('music.deezer_arl');

        return is_string($fromConfig) && $fromConfig !== '' ? $fromConfig : null;
    }

    public function deezerMode(): string
    {
        $stored = $this->setting('provider_deezer_mode');

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
        $stored = $this->setting('enabled_providers');
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
        $configPath = rtrim((string) config('music.path'), '/') ?: '/music';
        $stored = $this->setting('music_path');

        if (! is_string($stored) || trim($stored) === '') {
            return $configPath;
        }

        $stored = rtrim($stored, '/');
        if ($stored === $configPath) {
            return $stored;
        }

        // Synology host paths (/volume1/music, /volume2/music, …) are never valid
        // inside the container — the bind mount is always MUSIC_PATH (/music).
        if (preg_match('#^/volume\d+/#', $stored) === 1) {
            return $configPath;
        }

        // Prefer the container/env mount when Settings points at a missing path.
        $configReal = realpath($configPath);
        $storedReal = realpath($stored);
        if ($configReal !== false && ($storedReal === false || $storedReal !== $configReal)) {
            return $configPath;
        }

        return $stored;
    }

    public function defaultFormat(): AudioFormat
    {
        $stored = $this->setting('default_format');
        $format = AudioFormat::tryFrom((string) ($stored ?? config('music.default_format')));

        return $format ?? AudioFormat::Mp3_320;
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

    private function authenticatedUserId(): ?int
    {
        $id = auth()->id();

        return $id !== null ? (int) $id : null;
    }

    private function setting(string $key): ?string
    {
        if (! Schema::hasTable('settings')) {
            return null;
        }

        return $this->settings->get($key);
    }
}
