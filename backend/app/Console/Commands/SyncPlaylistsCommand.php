<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\SyncSavedPlaylist\SyncSavedPlaylistCommand;
use App\Application\SyncSavedPlaylist\SyncSavedPlaylistHandler;
use App\Domain\Music\Contracts\SavedPlaylistRepository;
use Illuminate\Console\Command;

final class SyncPlaylistsCommand extends Command
{
    protected $signature = 'playlists:sync';

    protected $description = 'Sync saved playlists that are due based on sync_interval_minutes';

    public function handle(
        SavedPlaylistRepository $playlists,
        SyncSavedPlaylistHandler $syncHandler,
    ): int {
        $due = $playlists->listDueForSync();

        if ($due === []) {
            $this->info('No playlists due for sync.');

            return self::SUCCESS;
        }

        $dispatched = 0;

        foreach ($due as $playlist) {
            $result = $syncHandler->handle(new SyncSavedPlaylistCommand((int) $playlist['id']));

            if ($result === null || ($result['already_running'] ?? false)) {
                continue;
            }

            $this->line(sprintf(
                'Dispatched sync for playlist #%d (%s)',
                $playlist['id'],
                $playlist['title'] ?? $playlist['url'],
            ));
            $dispatched++;
        }

        $this->info("Dispatched {$dispatched} playlist sync job(s).");

        return self::SUCCESS;
    }
}
