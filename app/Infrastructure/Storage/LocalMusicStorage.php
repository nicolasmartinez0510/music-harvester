<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

use App\Domain\Music\Models\Track;
use Illuminate\Support\Str;

final class LocalMusicStorage
{
    public function __construct(
        private string $basePath,
    ) {}

    public function basePath(): string
    {
        return rtrim($this->basePath, '/');
    }

    public function trackDirectory(Track $track): string
    {
        $artist = Str::slug($track->artist?->name ?? 'Unknown Artist');
        $album = Str::slug($track->album?->title ?? 'Unknown Album');

        return "{$this->basePath()}/{$artist}/{$album}";
    }

    public function trackFilename(Track $track, string $extension): string
    {
        $index = str_pad((string) ($track->index ?? 1), 2, '0', STR_PAD_LEFT);
        $title = Str::slug($track->title);

        return "{$index} - {$title}.{$extension}";
    }

    public function ensureDirectory(string $path): void
    {
        if (! is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }
}
