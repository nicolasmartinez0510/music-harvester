<?php

declare(strict_types=1);

namespace App\Application\ListProviders;

use App\Application\Settings\ProviderSettingsResolver;
use App\Domain\Music\Contracts\CatalogSource;
use App\Domain\Music\Contracts\MusicProvider;
use App\Domain\Music\ValueObjects\AudioFormat;
use App\Infrastructure\Providers\CatalogSourceRegistry;
use App\Infrastructure\Providers\MusicProviderRegistry;

final readonly class ListProvidersHandler
{
    public function __construct(
        private MusicProviderRegistry $providers,
        private CatalogSourceRegistry $catalogSources,
        private ProviderSettingsResolver $settings,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function handle(): array
    {
        $catalogNames = array_map(
            static fn (CatalogSource $source): string => $source->name(),
            $this->catalogSources->all(),
        );

        $result = [];

        foreach ($this->providers->all() as $provider) {
            $result[] = $this->describe($provider, in_array($provider->name(), $catalogNames, true));
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(MusicProvider $provider, bool $hasCatalog): array
    {
        $name = $provider->name();

        return match ($name) {
            'deezer' => [
                'name' => $name,
                'configured' => $this->settings->isArlConfigured()
                    || $this->settings->deezerMode() === 'hybrid',
                'qualities' => [AudioFormat::Flac->value, AudioFormat::Mp3_320->value],
                'has_catalog' => $hasCatalog,
                'mode' => $this->settings->deezerMode(),
            ],
            default => [
                'name' => $name,
                'configured' => $this->settings->isFileConfigured(
                    $this->settings->youtubeMusicCookiesPath(),
                ),
                'qualities' => [AudioFormat::Mp3_320->value, AudioFormat::M4a->value],
                'has_catalog' => $hasCatalog,
            ],
        };
    }
}
