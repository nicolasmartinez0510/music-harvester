<?php

declare(strict_types=1);

namespace App\Application\IndexDownloadedTracks;

use App\Domain\Music\Models\Track;
use App\Infrastructure\Storage\LocalMusicStorage;

/**
 * Pairs resolved tracks with files already on disk using the same filenames
 * the downloaders write (album layout and playlist layout).
 */
final class DownloadedTrackFileMatcher
{
    private const EXTENSIONS = ['flac', 'mp3', 'm4a', 'ogg', 'opus', 'aac', 'wav'];

    public function __construct(
        private LocalMusicStorage $storage,
    ) {}

    /**
     * @param  list<Track>  $tracks
     * @param  list<string>  $files
     * @return list<array{track: Track, path: string}>
     */
    public function match(array $tracks, array $files): array
    {
        $byName = [];
        foreach ($files as $path) {
            if ($path === '') {
                continue;
            }
            $byName[strtolower(basename($path))] = $path;
        }

        $matched = [];
        $used = [];
        $unmatched = [];

        foreach ($tracks as $track) {
            if ($track->id === null || $track->id === '') {
                continue;
            }

            $path = $this->findNamedFile($track, $byName, $used);
            if ($path === null) {
                $unmatched[] = $track;

                continue;
            }

            $matched[] = ['track' => $track, 'path' => $path];
            $used[$path] = true;
        }

        $unused = [];
        foreach ($byName as $path) {
            if (! isset($used[$path])) {
                $unused[] = $path;
            }
        }

        if (count($unmatched) === 1 && count($unused) === 1) {
            $matched[] = ['track' => $unmatched[0], 'path' => $unused[0]];
        }

        return $matched;
    }

    /**
     * @param  array<string, string>  $byName
     * @param  array<string, true>  $used
     */
    private function findNamedFile(Track $track, array $byName, array $used): ?string
    {
        foreach (self::EXTENSIONS as $extension) {
            foreach ([
                $this->storage->trackFilename($track, $extension),
                $this->storage->playlistTrackFilename($track, $extension),
            ] as $filename) {
                $path = $byName[strtolower($filename)] ?? null;
                if ($path !== null && ! isset($used[$path])) {
                    return $path;
                }
            }
        }

        return null;
    }
}
