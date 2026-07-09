<?php

declare(strict_types=1);

namespace App\Domain\Music\Contracts;

use App\Domain\Music\ValueObjects\DownloadOptions;
use App\Domain\Music\ValueObjects\DownloadResult;
use App\Domain\Music\ValueObjects\ResolvedItem;
use App\Domain\Music\ValueObjects\ResolvedMusic;

interface MusicProvider
{
    public function name(): string;

    public function supports(string $url): bool;

    public function resolve(string $url): ResolvedMusic;

    public function download(ResolvedItem $item, DownloadOptions $options): DownloadResult;
}
