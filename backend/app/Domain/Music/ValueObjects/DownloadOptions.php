<?php

declare(strict_types=1);

namespace App\Domain\Music\ValueObjects;

final readonly class DownloadOptions
{
    public function __construct(
        public AudioFormat $format,
        public string $musicPath,
        public string $provider = 'youtube_music',
        public ?string $cookiesPath = null,
        public ?string $deezerArl = null,
        public string $deezerMode = 'native',
        public ?string $targetDirectory = null,
    ) {}
}
