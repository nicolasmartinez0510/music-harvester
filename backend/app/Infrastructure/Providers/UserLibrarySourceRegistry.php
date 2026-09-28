<?php

declare(strict_types=1);

namespace App\Infrastructure\Providers;

use App\Application\Settings\ProviderSettingsResolver;
use App\Domain\Music\Contracts\UserLibrarySource;
use Illuminate\Support\Collection;

final class UserLibrarySourceRegistry
{
    /** @var Collection<int, UserLibrarySource> */
    private Collection $sources;

    /**
     * @param  iterable<UserLibrarySource>  $sources
     */
    public function __construct(
        iterable $sources,
        private ProviderSettingsResolver $settings,
    ) {
        $this->sources = collect($sources);
    }

    public function findByName(string $name): ?UserLibrarySource
    {
        return $this->enabled()->first(fn (UserLibrarySource $source) => $source->name() === $name);
    }

    /**
     * @return list<UserLibrarySource>
     */
    public function all(): array
    {
        return $this->enabled()->values()->all();
    }

    /**
     * Sources that are enabled and have credentials for library access.
     *
     * @return list<UserLibrarySource>
     */
    public function available(): array
    {
        return $this->enabled()
            ->filter(fn (UserLibrarySource $source) => $source->isLibraryAvailable())
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, UserLibrarySource>
     */
    private function enabled(): Collection
    {
        $enabled = $this->settings->enabledProviders();

        return $this->sources->filter(
            fn (UserLibrarySource $source) => in_array($source->name(), $enabled, true),
        );
    }
}
