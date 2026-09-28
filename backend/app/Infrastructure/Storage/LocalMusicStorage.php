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

    private function root(?string $basePath): string
    {
        $path = $basePath !== null && $basePath !== '' ? $basePath : $this->basePath;

        return rtrim($path, '/');
    }

    public function trackDirectory(Track $track, ?string $basePath = null): string
    {
        $artist = Str::slug($track->artist?->name ?? 'Unknown Artist');
        $album = Str::slug($track->album?->title ?? 'Unknown Album');
        $root = $this->root($basePath);

        return "{$root}/{$artist}/{$album}";
    }

    public function trackFilename(Track $track, string $extension): string
    {
        $index = str_pad((string) ($track->index ?? 1), 2, '0', STR_PAD_LEFT);
        $title = Str::slug($track->title);

        return "{$index} - {$title}.{$extension}";
    }

    public function playlistDirectory(int $playlistId, ?string $title, ?string $basePath = null): string
    {
        $slug = Str::slug((string) ($title ?: 'playlist')) ?: 'playlist';

        return $this->root($basePath)."/playlists/{$playlistId}-{$slug}";
    }

    public function playlistFolderName(int $playlistId, ?string $title): string
    {
        $slug = Str::slug((string) ($title ?: 'playlist')) ?: 'playlist';

        return "{$playlistId}-{$slug}";
    }

    /**
     * All on-disk folders for a saved playlist id (`{id}-*` under playlists/).
     *
     * @return list<string>
     */
    public function playlistDirectoriesForId(int $playlistId, ?string $basePath = null): array
    {
        $base = $this->root($basePath).'/playlists';
        if (! is_dir($base)) {
            return [];
        }

        $prefix = $playlistId.'-';
        $items = scandir($base);
        if ($items === false) {
            return [];
        }

        $dirs = [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || ! str_starts_with($item, $prefix)) {
                continue;
            }
            $path = $base.'/'.$item;
            if (is_dir($path)) {
                $dirs[] = $path;
            }
        }

        return $dirs;
    }

    public function playlistTrackFilename(Track $track, string $extension): string
    {
        $index = str_pad((string) ($track->index ?? 1), 2, '0', STR_PAD_LEFT);
        $artist = Str::slug($track->artist?->name ?? 'Unknown Artist');
        $title = Str::slug($track->title);

        return "{$index} - {$artist} - {$title}.{$extension}";
    }

    public function ensureDirectory(string $path): void
    {
        if (! is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }
}
