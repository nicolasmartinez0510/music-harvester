<?php

declare(strict_types=1);

namespace App\Application\ListSavedPlaylists;

use App\Domain\Music\Contracts\SavedPlaylistRepository;

final readonly class ListSavedPlaylistsQuery
{
    public function __construct(
        public ?int $userId = null,
        public bool $includeUnowned = false,
    ) {}
}

final readonly class ListSavedPlaylistsHandler
{
    public function __construct(
        private SavedPlaylistRepository $playlists,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function handle(ListSavedPlaylistsQuery $query = new ListSavedPlaylistsQuery): array
    {
        $items = [];

        foreach ($this->playlists->listAll($query->userId, $query->includeUnowned) as $playlist) {
            $counts = $this->playlists->trackCounts((int) $playlist['id']);
            $items[] = array_merge($playlist, ['counts' => $counts]);
        }

        return $items;
    }
}
