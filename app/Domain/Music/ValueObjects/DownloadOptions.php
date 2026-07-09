<?php

declare(strict_types=1);

namespace App\Domain\Music\ValueObjects;

final readonly class DownloadOptions
{
    public function __construct(
        public AudioFormat $format,
        public string $musicPath,
        public ?string $cookiesPath = null,
    ) {}
}
