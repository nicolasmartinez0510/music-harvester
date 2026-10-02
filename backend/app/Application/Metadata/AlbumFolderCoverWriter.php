<?php

declare(strict_types=1);

namespace App\Application\Metadata;

/**
 * Writes album artwork next to the audio file ({artist}/{album}/cover.jpg).
 * Skips files that still sit directly in a playlist folder, so the playlist
 * root keeps only the sidecar JPEG from cover modes.
 */
final class AlbumFolderCoverWriter
{
    public function writeIfMissing(string $audioPath, ?string $coverBytes): void
    {
        if ($coverBytes === null || $coverBytes === '') {
            return;
        }

        if ($this->audioIsDirectlyInPlaylistRoot($audioPath)) {
            return;
        }

        $directory = dirname($audioPath);
        $target = $directory.'/cover.jpg';
        if (is_file($target) && filesize($target) > 0) {
            return;
        }

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            return;
        }

        file_put_contents($target, $coverBytes);
    }

    private function audioIsDirectlyInPlaylistRoot(string $audioPath): bool
    {
        $folder = dirname(str_replace('\\', '/', $audioPath));

        return basename(dirname($folder)) === 'playlists';
    }
}
