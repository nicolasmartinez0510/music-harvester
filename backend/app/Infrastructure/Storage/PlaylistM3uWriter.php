<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

use App\Domain\Music\ValueObjects\PlaylistTrackStatus;
use RuntimeException;

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
            $status = (string) ($track['status'] ?? '');
            if (! in_array($status, [
                PlaylistTrackStatus::Downloaded->value,
                PlaylistTrackStatus::Existing->value,
            ], true)) {
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
            $basename = $this->relativeFrom($directory, $filePath);

            $lines[] = '#EXTINF:-1,'.$artist.' - '.$trackTitle;
            $lines[] = $basename;
        }

        $contents = implode("\n", $lines)."\n";
        $this->replaceContents($m3uPath, $contents);

        return $m3uPath;
    }

    /**
     * Playlist folders created by the old root worker leave a root-owned .m3u (0644).
     * www-data cannot overwrite that file. The directory is opened on startup, so
     * unlink and write a new file owned by the current user.
     */
    private function replaceContents(string $path, string $contents): void
    {
        if (is_file($path) && ! is_writable($path)) {
            @chmod($path, 0666);
        }

        if (is_file($path) && ! is_writable($path) && ! @unlink($path)) {
            throw new RuntimeException(
                'No se puede actualizar la playlist porque el archivo no es escribible: '.$path,
            );
        }

        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException('No se puede escribir la playlist: '.$path);
        }
    }

    private function sanitizePlaylistName(string $title): string
    {
        return str_replace(["\r", "\n"], ' ', $title);
    }

    private function relativeFrom(string $directory, string $filePath): string
    {
        $from = $this->absolute($directory);
        $to = $this->absolute($filePath);

        if ($to === $from || str_starts_with($to, $from.'/')) {
            $relative = ltrim(substr($to, strlen($from)), '/');

            return $relative !== '' ? $relative : basename($filePath);
        }

        $fromParts = $from === '' ? [] : explode('/', trim($from, '/'));
        $toParts = $to === '' ? [] : explode('/', trim($to, '/'));

        while ($fromParts !== [] && $toParts !== [] && $fromParts[0] === $toParts[0]) {
            array_shift($fromParts);
            array_shift($toParts);
        }

        return str_repeat('../', count($fromParts)).implode('/', $toParts);
    }

    private function absolute(string $path): string
    {
        $resolved = realpath($path);
        $value = $resolved !== false ? $resolved : $path;

        return rtrim(str_replace('\\', '/', $value), '/');
    }
}
