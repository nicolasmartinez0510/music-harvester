<?php

declare(strict_types=1);

namespace App\Application\DeleteSavedPlaylist;

use App\Domain\Music\Contracts\SavedPlaylistRepository;
use App\Infrastructure\Queue\PlaylistSyncQueueCanceller;
use App\Infrastructure\Storage\DownloadedFilesCleanup;
use App\Infrastructure\Storage\LocalMusicStorage;

final readonly class DeleteSavedPlaylistCommand
{
    public function __construct(
        public int $id,
    ) {}
}

final readonly class DeleteSavedPlaylistHandler
{
    public function __construct(
        private SavedPlaylistRepository $playlists,
        private PlaylistSyncQueueCanceller $queueCanceller,
        private LocalMusicStorage $storage,
        private DownloadedFilesCleanup $filesCleanup,
    ) {}

    public function handle(DeleteSavedPlaylistCommand $command): bool
    {
        $playlist = $this->playlists->find($command->id);

        if ($playlist === null) {
            return false;
        }

        $tracks = $this->playlists->listTracks($command->id);
        $filePaths = [];
        foreach ($tracks as $track) {
            $path = $track['file_path'] ?? null;
            if (is_string($path) && $path !== '') {
                $filePaths[] = $path;
            }
        }

        $directories = $this->storage->playlistDirectoriesForId($command->id);
        $expectedDir = $this->storage->playlistDirectory(
            $command->id,
            is_string($playlist['title'] ?? null) ? $playlist['title'] : null,
        );
        if (! in_array($expectedDir, $directories, true)) {
            $directories[] = $expectedDir;
        }

        // Stop pending/reserved sync jobs, then drop DB so a running job aborts.
        $this->queueCanceller->cancelForPlaylist($command->id);

        if (! $this->playlists->delete($command->id)) {
            return false;
        }

        $this->filesCleanup->deletePaths([...$filePaths, ...$directories]);

        return true;
    }
}
