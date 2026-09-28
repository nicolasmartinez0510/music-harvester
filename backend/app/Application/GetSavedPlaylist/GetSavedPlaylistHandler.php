<?php

declare(strict_types=1);

namespace App\Application\GetSavedPlaylist;

use App\Domain\Music\Contracts\SavedPlaylistRepository;

final readonly class GetSavedPlaylistQuery
{
    public function __construct(
        public int $id,
    ) {}
}

final readonly class GetSavedPlaylistHandler
{
    public function __construct(
        private SavedPlaylistRepository $playlists,
    ) {}

    /**
     * @return array{playlist: array<string, mixed>, tracks: list<array<string, mixed>>, counts: array<string, int>}|null
     */
    public function handle(GetSavedPlaylistQuery $query): ?array
    {
        $playlist = $this->playlists->find($query->id);

        if ($playlist === null) {
            return null;
        }

        return [
            'playlist' => $playlist,
            'tracks' => $this->playlists->listTracks($query->id),
            'counts' => $this->playlists->trackCounts($query->id),
        ];
    }
}
