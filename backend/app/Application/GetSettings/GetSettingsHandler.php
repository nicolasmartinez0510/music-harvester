<?php

declare(strict_types=1);

namespace App\Application\GetSettings;

use App\Application\Settings\ProviderSettingsResolver;
use App\Domain\Music\Contracts\SettingsRepository;

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

        $musicPath = $this->providerSettings->musicPath();
        $defaultFormat = $this->providerSettings->defaultFormat()->value;
        $maxConcurrency = $stored['max_concurrency'] ?? (string) config('music.max_concurrency');

        $youtubeCookies = $this->providerSettings->youtubeMusicCookiesPath();
        $deezerArl = $this->providerSettings->deezerArl();
        $deezerMode = $this->providerSettings->deezerMode();
        $enabledProviders = implode(',', $this->providerSettings->enabledProviders());

        $youtubeConfigured = $this->providerSettings->isFileConfigured($youtubeCookies);
        $arlConfigured = $this->providerSettings->isArlConfigured($deezerArl);

        return [
            'music_path' => $musicPath,
            'default_format' => $defaultFormat,
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
            'metadata_enrich_enabled' => $this->boolSetting($stored['metadata_enrich_enabled'] ?? null, (bool) config('music.metadata_enrich_enabled', true)),
            'metadata_embed_cover' => $this->boolSetting($stored['metadata_embed_cover'] ?? null, (bool) config('music.metadata_embed_cover', true)),
            'metadata_embed_lyrics' => $this->boolSetting($stored['metadata_embed_lyrics'] ?? null, (bool) config('music.metadata_embed_lyrics', true)),
        ];
    }

    private function emailVerificationEnabled(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        return ! in_array(strtolower((string) $value), ['0', 'false', 'off'], true);
    }

    private function boolSetting(mixed $value, bool $default): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }

        return ! in_array(strtolower((string) $value), ['0', 'false', 'off', 'no'], true);
    }
}
