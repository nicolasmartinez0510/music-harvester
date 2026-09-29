<?php

declare(strict_types=1);

namespace App\Domain\Music\ValueObjects;

final readonly class MetadataEnrichContext
{
    public function __construct(
        public ?string $arl = null,
        public bool $embedCover = true,
        public bool $embedLyrics = true,
        public ?ResolvedKind $kind = null,
        public ?int $trackTotal = null,
    ) {}
}
