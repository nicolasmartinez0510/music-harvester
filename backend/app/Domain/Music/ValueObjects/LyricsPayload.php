<?php

declare(strict_types=1);

namespace App\Domain\Music\ValueObjects;

final readonly class LyricsPayload
{
    public function __construct(
        public ?string $plain,
        public ?string $synced,
    ) {}
}
