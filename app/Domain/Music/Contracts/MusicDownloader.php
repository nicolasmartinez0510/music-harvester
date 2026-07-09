<?php

declare(strict_types=1);

namespace App\Domain\Music\Contracts;

use App\Domain\Music\ValueObjects\DownloadOptions;
use App\Domain\Music\ValueObjects\DownloadResult;

interface MusicDownloader
{
    /**
     * @return array<string, mixed>
     */
    public function fetchMetadata(string $url, ?string $cookiesPath = null): array;

    public function download(string $url, DownloadOptions $options, string $outputTemplate): DownloadResult;

    public function isAvailable(): bool;
}
