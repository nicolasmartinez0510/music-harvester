<?php

declare(strict_types=1);

namespace App\Application\GetSettings;

use App\Application\Settings\ProviderSettingsResolver;
use App\Domain\Music\Contracts\SettingsRepository;
use App\Domain\Music\ValueObjects\AudioFormat;

final readonly class GetSettingsHandler
{
    public function __construct(
        private SettingsRepository $settings,
        private ProviderSettingsResolver $providerSettings,
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

        $youtubeCookies = $this->providerSettings->youtubeMusicCookiesPath();
        $deezerArl = $this->providerSettings->deezerArl();
        $deezerMode = $this->providerSettings->deezerMode();
        $enabledProviders = implode(',', $this->providerSettings->enabledProviders());

        $format = AudioFormat::tryFrom((string) $defaultFormat) ?? AudioFormat::Mp3_320;
        $youtubeConfigured = $this->providerSettings->isFileConfigured($youtubeCookies);
        $arlConfigured = $this->providerSettings->isArlConfigured($deezerArl);

        return [
            'music_path' => is_string($musicPath) ? $musicPath : (string) config('music.path'),
            'default_format' => $format->value,
            'max_concurrency' => max(1, (int) $maxConcurrency),
            'enabled_providers' => $enabledProviders,
            'provider_youtube_music_cookies_path' => $youtubeCookies,
            'provider_youtube_music_cookies_configured' => $youtubeConfigured,
            'provider_deezer_arl_configured' => $arlConfigured,
            'provider_deezer_mode' => $deezerMode,
            // Legacy aliases for older clients
            'cookies_path' => $youtubeCookies,
            'cookies_configured' => $youtubeConfigured,
            'email_verification_enabled' => $this->emailVerificationEnabled($stored['email_verification_enabled'] ?? null),
        ];
    }

    private function emailVerificationEnabled(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        return ! in_array(strtolower((string) $value), ['0', 'false', 'off'], true);
    }
}
