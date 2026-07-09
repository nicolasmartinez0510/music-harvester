<?php

declare(strict_types=1);

namespace App\Domain\Music\ValueObjects;

final readonly class ResolvedMusic
{
    /**
     * @param  list<ResolvedItem>  $items
     */
    public function __construct(
        public string $provider,
        public ResolvedKind $kind,
        public string $title,
        public array $items,
        public string $sourceUrl,
    ) {}
}
