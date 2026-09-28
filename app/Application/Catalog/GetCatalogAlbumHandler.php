<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Domain\Music\Contracts\CatalogSource;
use App\Domain\Music\ValueObjects\CatalogAlbum;
use App\Infrastructure\Providers\CatalogSourceRegistry;
use InvalidArgumentException;

final readonly class GetCatalogAlbumQuery
{
    public function __construct(
        public string $provider,
        public string $id,
    ) {}
}

final readonly class GetCatalogAlbumHandler
{
    public function __construct(
        private CatalogSourceRegistry $catalogSources,
    ) {}

    public function handle(GetCatalogAlbumQuery $query): CatalogAlbum
    {
        return $this->requireSource($query->provider)->getAlbum($query->id);
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
