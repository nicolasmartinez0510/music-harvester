<?php

declare(strict_types=1);

namespace App\Domain\Music\ValueObjects;

final readonly class CatalogAlbum
{
    /**
     * @param  list<CatalogHit>  $tracks
     */
    public function __construct(
        public string $id,
        public string $title,
        public ?string $artistName = null,
        public ?string $coverUrl = null,
        public ?string $canonicalUrl = null,
        public int $nbTracks = 0,
        public array $tracks = [],
    ) {}
}
