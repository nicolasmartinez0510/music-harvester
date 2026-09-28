<?php

declare(strict_types=1);

namespace App\Domain\Music\Models;

final readonly class Track
{
    public function __construct(
        public string $title,
        public ?Artist $artist = null,
        public ?Album $album = null,
        public ?int $index = null,
        public ?string $id = null,
        public ?string $duration = null,
    ) {}
}
