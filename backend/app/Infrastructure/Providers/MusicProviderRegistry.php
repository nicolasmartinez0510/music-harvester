<?php

declare(strict_types=1);

namespace App\Infrastructure\Providers;

use App\Application\Settings\ProviderSettingsResolver;
use App\Domain\Music\Contracts\MusicProvider;
use Illuminate\Support\Collection;

final class MusicProviderRegistry
{
    /** @var Collection<int, MusicProvider> */
    private Collection $providers;

    /**
     * @param  iterable<MusicProvider>  $providers
     */
    public function __construct(
        iterable $providers,
        private ProviderSettingsResolver $settings,
    ) {
        $this->providers = collect($providers);
    }

    public function resolveForUrl(string $url): ?MusicProvider
    {
        return $this->enabled()->first(fn (MusicProvider $provider) => $provider->supports($url));
    }

    public function findByName(string $name): ?MusicProvider
    {
        return $this->enabled()->first(fn (MusicProvider $provider) => $provider->name() === $name);
    }

    /**
     * @return list<MusicProvider>
     */
    public function all(): array
    {
        return $this->enabled()->values()->all();
    }

    /**
     * @return Collection<int, MusicProvider>
     */
    private function enabled(): Collection
    {
        $enabled = $this->settings->enabledProviders();

        return $this->providers->filter(
            fn (MusicProvider $provider) => in_array($provider->name(), $enabled, true),
        );
    }
}
