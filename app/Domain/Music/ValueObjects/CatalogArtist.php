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
        public ?string $description = null,
    ) {}

    public function withDescription(?string $description): self
    {
        return new self(
            id: $this->id,
            name: $this->name,
            coverUrl: $this->coverUrl,
            canonicalUrl: $this->canonicalUrl,
            nbFans: $this->nbFans,
            topTracks: $this->topTracks,
            albums: $this->albums,
            description: $description,
        );
    }
}
