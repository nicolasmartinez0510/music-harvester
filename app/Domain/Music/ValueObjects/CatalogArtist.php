<?php

declare(strict_types=1);

namespace App\Domain\Music\ValueObjects;

final readonly class CatalogArtist
{
    /**
     * @param  list<CatalogHit>  $topTracks
     * @param  list<CatalogHit>  $albums
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $coverUrl = null,
        public ?string $canonicalUrl = null,
        public int $nbFans = 0,
        public array $topTracks = [],
        public array $albums = [],
    ) {}
}
