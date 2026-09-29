<?php

declare(strict_types=1);

namespace App\Application\UpdateSettings;

use App\Application\GetSettings\GetSettingsHandler;
use App\Domain\Music\Contracts\SettingsRepository;
use App\Domain\Music\ValueObjects\AudioFormat;
use App\Infrastructure\Persistence\EloquentSettingsRepository;

final readonly class UpdateSettingsCommand
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function __construct(
        public array $values,
    ) {}
}

final readonly class UpdateSettingsHandler
{
    public function __construct(
        private SettingsRepository $settings,
        private GetSettingsHandler $getSettings,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(UpdateSettingsCommand $command): array
    {
        foreach ($command->values as $key => $value) {
            if (! in_array($key, EloquentSettingsRepository::KEYS, true)) {
                continue;
            }

            if ($key === 'default_format' && is_string($value)) {
                AudioFormat::from($value);
            }

            if ($key === 'provider_deezer_mode' && is_string($value) && ! in_array($value, ['native', 'hybrid'], true)) {
                continue;
            }

            if ($key === 'max_concurrency') {
                $value = (string) max(1, (int) $value);
            }

            if (in_array($key, [
                'email_verification_enabled',
                'metadata_enrich_enabled',
                'metadata_embed_cover',
                'metadata_embed_lyrics',
            ], true)) {
                $this->settings->set($key, filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0');

                continue;
            }

            if ($value === null || $value === '') {
                $this->settings->set($key, null);

                if ($key === 'cookies_path') {
                    $this->settings->set('provider_youtube_music_cookies_path', null);
                }

                continue;
            }

            $this->settings->set($key, (string) $value);

            // Keep legacy cookies_path and new key in sync
            if ($key === 'cookies_path') {
                $this->settings->set('provider_youtube_music_cookies_path', (string) $value);
            }

            if ($key === 'provider_youtube_music_cookies_path') {
                $this->settings->set('cookies_path', (string) $value);
            }
        }

        return $this->getSettings->handle();
    }
}
