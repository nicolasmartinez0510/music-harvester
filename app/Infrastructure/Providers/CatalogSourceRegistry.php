<?php

declare(strict_types=1);

namespace App\Infrastructure\Providers;

use App\Application\Settings\ProviderSettingsResolver;
use App\Domain\Music\Contracts\CatalogSource;
use Illuminate\Support\Collection;

final class CatalogSourceRegistry
{
    /** @var Collection<int, CatalogSource> */
    private Collection $sources;

    /**
     * @param  iterable<CatalogSource>  $sources
     */
    public function __construct(
        iterable $sources,
        private ProviderSettingsResolver $settings,
    ) {
        $this->sources = collect($sources);
    }

    public function findByName(string $name): ?CatalogSource
    {
        return $this->enabled()->first(fn (CatalogSource $source) => $source->name() === $name);
    }

    /**
     * @return list<CatalogSource>
     */
    public function all(): array
    {
        return $this->enabled()->values()->all();
    }

    /**
     * @return Collection<int, CatalogSource>
     */
    private function enabled(): Collection
    {
        $enabled = $this->settings->enabledProviders();

        return $this->sources->filter(
            fn (CatalogSource $source) => in_array($source->name(), $enabled, true),
        );
    }
}
