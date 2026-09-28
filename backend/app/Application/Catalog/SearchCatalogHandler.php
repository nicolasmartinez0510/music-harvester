<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Domain\Music\Contracts\CatalogSource;
use App\Domain\Music\ValueObjects\CatalogType;
use App\Infrastructure\Providers\CatalogSourceRegistry;
use InvalidArgumentException;

final readonly class SearchCatalogQuery
{
    public function __construct(
        public string $provider,
        public string $q,
        public CatalogType $type = CatalogType::All,
        public int $limit = 25,
        public int $index = 0,
    ) {}
}

final readonly class SearchCatalogHandler
{
    public function __construct(
        private CatalogSourceRegistry $catalogSources,
    ) {}

    /**
     * @return list<\App\Domain\Music\ValueObjects\CatalogHit>
     */
    public function handle(SearchCatalogQuery $query): array
    {
        $source = $this->requireSource($query->provider);
        $limit = max(1, min(100, $query->limit));
        $q = trim($query->q);

        if ($q === '') {
            return [];
        }

        return $source->search($q, $query->type, $limit, max(0, $query->index));
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
