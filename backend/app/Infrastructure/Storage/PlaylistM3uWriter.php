<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

use App\Domain\Music\ValueObjects\PlaylistTrackStatus;

final class PlaylistM3uWriter
{
    public function __construct(
        private LocalMusicStorage $storage,
    ) {}

    /**
     * @param  array{id: int, title: string|null}  $playlist
     * @param  list<array{title: string, artist: string|null, status: string, file_path: string|null, position?: int}>  $tracks
     */
    public function write(array $playlist, array $tracks, ?string $basePath = null): string
    {
        $playlistId = (int) $playlist['id'];
        $title = is_string($playlist['title'] ?? null) && $playlist['title'] !== ''
            ? (string) $playlist['title']
            : 'Playlist '.$playlistId;

        $directory = $this->storage->playlistDirectory($playlistId, $title, $basePath);
        $this->storage->ensureDirectory($directory);

        $folderName = $this->storage->playlistFolderName($playlistId, $title);
        $m3uPath = $directory.'/'.$folderName.'.m3u';

        $lines = [
            '#EXTM3U',
            '#PLAYLIST:'.$this->sanitizePlaylistName($title),
        ];

        foreach ($tracks as $track) {
            if (($track['status'] ?? '') !== PlaylistTrackStatus::Downloaded->value) {
                continue;
            }

            $filePath = $track['file_path'] ?? null;
            if (! is_string($filePath) || $filePath === '' || ! is_file($filePath)) {
                continue;
            }

            $artist = is_string($track['artist'] ?? null) && $track['artist'] !== ''
                ? (string) $track['artist']
                : 'Unknown Artist';
            $trackTitle = (string) ($track['title'] ?? 'Unknown Title');
            $basename = basename($filePath);

            $lines[] = '#EXTINF:-1,'.$artist.' - '.$trackTitle;
            $lines[] = $basename;
        }

        $contents = implode("\n", $lines)."\n";
        file_put_contents($m3uPath, $contents);

        return $m3uPath;
    }

    private function sanitizePlaylistName(string $title): string
    {
        return str_replace(["\r", "\n"], ' ', $title);
    }
}
