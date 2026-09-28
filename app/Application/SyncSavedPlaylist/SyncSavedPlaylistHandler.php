<?php

declare(strict_types=1);

namespace App\Application\SyncSavedPlaylist;

use App\Domain\Music\Contracts\SavedPlaylistRepository;
use App\Domain\Music\ValueObjects\PlaylistSyncStatus;
use App\Jobs\ProcessPlaylistSyncJob;

final readonly class SyncSavedPlaylistCommand
{
    public function __construct(
        public int $id,
    ) {}
}

final readonly class SyncSavedPlaylistHandler
{
    public function __construct(
        private SavedPlaylistRepository $playlists,
    ) {}

    /**
     * @return array<string, mixed>|null  null = not found; empty array with error key if already running
     */
    public function handle(SyncSavedPlaylistCommand $command): ?array
    {
        $playlist = $this->playlists->find($command->id);

        if ($playlist === null) {
            return null;
        }

        if (($playlist['last_sync_status'] ?? '') === PlaylistSyncStatus::Running->value) {
            return [
                'playlist' => $playlist,
                'already_running' => true,
            ];
        }

        $this->playlists->updateSyncStatus($command->id, PlaylistSyncStatus::Running);
        ProcessPlaylistSyncJob::dispatch($command->id);

        $updated = $this->playlists->find($command->id) ?? $playlist;

        return [
            'playlist' => $updated,
            'already_running' => false,
        ];
    }
}
