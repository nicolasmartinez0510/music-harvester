<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Domain\Music\Contracts\ArtistBiographyLookup;
use App\Domain\Music\Contracts\CatalogSource;
use App\Domain\Music\ValueObjects\CatalogArtist;
use App\Infrastructure\Providers\CatalogSourceRegistry;
use InvalidArgumentException;

final readonly class GetCatalogArtistQuery
{
    public function __construct(
        public string $provider,
        public string $id,
    ) {}
}

final readonly class GetCatalogArtistHandler
{
    public function __construct(
        private CatalogSourceRegistry $catalogSources,
        private ArtistBiographyLookup $biographies,
    ) {}

    public function handle(GetCatalogArtistQuery $query): CatalogArtist
    {
        $artist = $this->requireSource($query->provider)->getArtist($query->id);

        return $artist->withDescription($this->biographies->find($artist->name));
    }

    private function requireSource(string $provider): CatalogSource
    {
        $source = $this->catalogSources->findByName($provider);

        if ($source === null) {
            throw new InvalidArgumentException(sprintf(
                'Catalog provider [%s] is not available.',
                $provider,
            ));
        }

        return $source;
    }
}
