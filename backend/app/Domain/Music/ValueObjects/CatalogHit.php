<?php

declare(strict_types=1);

namespace App\Domain\Music\ValueObjects;

final readonly class CatalogHit
{
    public function __construct(
        public CatalogType $type,
        public string $id,
        public string $title,
        public ?string $subtitle = null,
        public ?string $coverUrl = null,
        public ?string $canonicalUrl = null,
        public ?int $nbTracks = null,
        public ?string $releaseDate = null,
        public ?int $fans = null,
        public ?string $recordType = null,
    ) {}
}
