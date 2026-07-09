<?php

declare(strict_types=1);

namespace App\Domain\Music\Models;

final readonly class Album
{
    public function __construct(
        public string $title,
        public ?Artist $artist = null,
        public ?string $id = null,
    ) {}
}
