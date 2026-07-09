<?php

declare(strict_types=1);

namespace App\Domain\Music\Models;

final readonly class Artist
{
    public function __construct(
        public string $name,
        public ?string $id = null,
    ) {}
}
