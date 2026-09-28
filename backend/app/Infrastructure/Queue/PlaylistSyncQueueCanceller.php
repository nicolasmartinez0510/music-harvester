<?php

declare(strict_types=1);

namespace App\Infrastructure\Queue;

use App\Jobs\ProcessPlaylistSyncJob;
use Illuminate\Support\Facades\DB;

/**
 * Removes pending/reserved database-queue jobs for a saved playlist sync.
 */
final class PlaylistSyncQueueCanceller
{
    public function cancelForPlaylist(int $playlistId): int
    {
        $deleted = 0;
        $rows = DB::table('jobs')->orderBy('id')->get(['id', 'payload']);

        foreach ($rows as $row) {
            if (! $this->payloadTargetsPlaylist((string) $row->payload, $playlistId)) {
                continue;
            }

            $deleted += (int) DB::table('jobs')->where('id', $row->id)->delete();
        }

        return $deleted;
    }

    private function payloadTargetsPlaylist(string $payloadJson, int $playlistId): bool
    {
        $payload = json_decode($payloadJson, true);
        if (! is_array($payload)) {
            return false;
        }

        $displayName = $payload['displayName'] ?? null;
        if ($displayName !== ProcessPlaylistSyncJob::class) {
            return false;
        }

        $command = $payload['data']['command'] ?? null;
        if (! is_string($command) || $command === '') {
            return false;
        }

        // Serialized PHP property: savedPlaylistId";i:{id};
        return (bool) preg_match(
            '/savedPlaylistId";i:'.$playlistId.';/',
            $command,
        );
    }
}
