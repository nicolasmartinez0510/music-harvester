<?php

declare(strict_types=1);

namespace App\Infrastructure\Providers;

use App\Domain\Music\Contracts\MusicProvider;
use Illuminate\Support\Collection;

final class MusicProviderRegistry
{
    /** @var Collection<int, MusicProvider> */
    private Collection $providers;

    /**
     * @param  iterable<MusicProvider>  $providers
     */
    public function __construct(iterable $providers)
    {
        $this->providers = collect($providers);
    }

    public function resolveForUrl(string $url): ?MusicProvider
    {
        return $this->providers->first(fn (MusicProvider $provider) => $provider->supports($url));
    }

    /**
     * @return list<MusicProvider>
     */
    public function all(): array
    {
        return $this->providers->values()->all();
    }
}
