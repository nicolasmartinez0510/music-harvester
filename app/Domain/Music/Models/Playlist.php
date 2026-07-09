<?php

declare(strict_types=1);

namespace App\Domain\Music\Models;

final readonly class Playlist
{
    /**
     * @param  list<Track>  $tracks
     */
    public function __construct(
        public string $title,
        public array $tracks = [],
        public ?string $id = null,
    ) {}
}
