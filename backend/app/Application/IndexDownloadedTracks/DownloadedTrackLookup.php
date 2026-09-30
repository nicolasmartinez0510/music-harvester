<?php

declare(strict_types=1);

namespace App\Application\IndexDownloadedTracks;

use App\Domain\Music\Contracts\DownloadedTrackRepository;
use App\Domain\Music\Models\Track;

/**
 * Reuses a file already on disk.
 * 1. Provider track id, when the provider sent one.
 * 2. Artist + title.
 * 3. Release year, only when artist + title matches more than one file.
 */
final class DownloadedTrackLookup
{
    /**
     * @return array<string, mixed>|null
     */
    public function present(
        DownloadedTrackRepository $index,
        ?int $userId,
        string $provider,
        Track $track,
    ): ?array {
        $id = $track->id;
        if (is_string($id) && $id !== '') {
            $byId = $index->findPresent($userId, $provider, $id);
            if ($byId !== null) {
                return $byId;
            }
        }

        $artist = $track->artist?->name;
        if (! is_string($artist) || trim($artist) === '' || trim($track->title) === '') {
            return null;
        }

        return $index->findPresentByIdentity($userId, $artist, $track->title, $track->releaseYear);
    }

    /**
     * Provider id, or a stable key when the provider did not send one.
     */
    public function indexId(Track $track): ?string
    {
        $id = $track->id;
        if (is_string($id) && $id !== '') {
            return $id;
        }

        $artist = trim((string) $track->artist?->name);
        $title = trim($track->title);
        if ($artist === '' || $title === '') {
            return null;
        }

        $year = $track->releaseYear === null ? '' : (string) $track->releaseYear;

        return 'identity:'.hash('sha256', mb_strtolower($artist).'|'.mb_strtolower($title).'|'.$year);
    }
}
