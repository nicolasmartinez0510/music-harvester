<?php

declare(strict_types=1);

namespace App\Application\DeleteSavedPlaylist;

use App\Application\Auth\LibraryPathResolver;
use App\Application\PlaylistCover\PlaylistCoverGenerator;
use App\Domain\Music\Contracts\DownloadedTrackRepository;
use App\Domain\Music\Contracts\SavedPlaylistRepository;
use App\Infrastructure\Queue\PlaylistSyncQueueCanceller;
use App\Infrastructure\Storage\DownloadedFilesCleanup;
use App\Infrastructure\Storage\LocalMusicStorage;
use App\Models\User;

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
        private LibraryPathResolver $paths,
        private DownloadedTrackRepository $downloadedTracks,
        private PlaylistCoverGenerator $covers,
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

        $ownerId = $playlist['user_id'] ?? null;
        $owner = is_int($ownerId) || (is_string($ownerId) && $ownerId !== '')
            ? User::query()->find((int) $ownerId)
            : null;
        $root = $this->paths->rootForPlaylist($owner);

        $directories = $this->storage->playlistDirectoriesForId($command->id, $root);
        $expectedDir = $this->storage->playlistDirectory(
            $command->id,
            is_string($playlist['title'] ?? null) ? $playlist['title'] : null,
            $root,
        );
        if (! in_array($expectedDir, $directories, true)) {
            $directories[] = $expectedDir;
        }

        // Stop pending/reserved sync jobs, then drop DB so a running job aborts.
        $this->queueCanceller->cancelForPlaylist($command->id);

        if (! $this->playlists->delete($command->id)) {
            return false;
        }

        $ownedPaths = [];
        foreach ($filePaths as $path) {
            if ($this->pathIsInside($path, $directories)) {
                $ownedPaths[] = $path;
            }
        }

        $this->filesCleanup->deletePaths([...$ownedPaths, ...$directories]);
        $this->covers->deleteCustom($command->id);
        $this->downloadedTracks->deleteByPaths($ownedPaths);

        return true;
    }

    /**
     * @param  list<string>  $directories
     */
    private function pathIsInside(string $path, array $directories): bool
    {
        $normalized = rtrim(str_replace('\\', '/', $path), '/');

        foreach ($directories as $directory) {
            $dir = rtrim(str_replace('\\', '/', $directory), '/');
            if ($dir !== '' && ($normalized === $dir || str_starts_with($normalized, $dir.'/'))) {
                return true;
            }
        }

        return false;
    }
}
