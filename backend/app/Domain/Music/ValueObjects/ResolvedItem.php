<?php

declare(strict_types=1);

namespace App\Domain\Music\ValueObjects;

use App\Domain\Music\Models\Album;
use App\Domain\Music\Models\Playlist;
use App\Domain\Music\Models\Track;

final readonly class ResolvedItem
{
    public function __construct(
        public ResolvedKind $kind,
        public Track|Album|Playlist $item,
        public int $position = 0,
    ) {}
}
