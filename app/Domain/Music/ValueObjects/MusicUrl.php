<?php

declare(strict_types=1);

namespace App\Domain\Music\ValueObjects;

use InvalidArgumentException;

final readonly class MusicUrl
{
    public function __construct(
        public string $value,
    ) {
        if (! filter_var($this->value, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('Invalid music URL.');
        }
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
