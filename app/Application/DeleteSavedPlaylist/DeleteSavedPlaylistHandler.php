<?php

declare(strict_types=1);

namespace App\Application\DeleteSavedPlaylist;

use App\Domain\Music\Contracts\SavedPlaylistRepository;

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
    ) {}

    public function handle(DeleteSavedPlaylistCommand $command): bool
    {
        return $this->playlists->delete($command->id);
    }
}
