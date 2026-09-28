<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Music\Contracts\SavedPlaylistRepository;
use App\Domain\Music\ValueObjects\AudioFormat;
use App\Domain\Music\ValueObjects\PlaylistSyncStatus;
use App\Domain\Music\ValueObjects\PlaylistTrackStatus;
use Illuminate\Support\Facades\DB;

final class EloquentSavedPlaylistRepository implements SavedPlaylistRepository
{
    public function create(
        string $provider,
        string $url,
        ?string $title = null,
        bool $syncEnabled = true,
        int $syncIntervalMinutes = 5,
        ?AudioFormat $defaultFormat = null,
    ): array {
        $id = (int) DB::table('saved_playlists')->insertGetId([
            'provider' => $provider,
            'url' => $url,
            'title' => $title,
            'sync_enabled' => $syncEnabled,
            'sync_interval_minutes' => max(1, $syncIntervalMinutes),
            'default_format' => $defaultFormat?->value,
            'last_synced_at' => null,
            'last_sync_status' => PlaylistSyncStatus::Idle->value,
            'last_sync_error' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $playlist = $this->find($id);

        if ($playlist === null) {
            throw new \RuntimeException('Failed to create saved playlist.');
        }

        return $playlist;
    }

    public function find(int $id): ?array
    {
        $row = DB::table('saved_playlists')->where('id', $id)->first();

        return $row ? $this->mapPlaylist((array) $row) : null;
    }

    public function listAll(): array
    {
        return DB::table('saved_playlists')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($row) => $this->mapPlaylist((array) $row))
            ->all();
    }

    public function update(int $id, array $attributes): ?array
    {
        $allowed = [];

        if (array_key_exists('sync_enabled', $attributes)) {
            $allowed['sync_enabled'] = (bool) $attributes['sync_enabled'];
        }

        if (array_key_exists('sync_interval_minutes', $attributes)) {
            $allowed['sync_interval_minutes'] = max(1, (int) $attributes['sync_interval_minutes']);
        }

        if (array_key_exists('default_format', $attributes)) {
            $allowed['default_format'] = $attributes['default_format'];
        }

        if (array_key_exists('title', $attributes)) {
            $allowed['title'] = $attributes['title'];
        }

        if ($allowed === []) {
            return $this->find($id);
        }

        $allowed['updated_at'] = now();
        DB::table('saved_playlists')->where('id', $id)->update($allowed);

        return $this->find($id);
    }

    public function delete(int $id): bool
    {
        return DB::table('saved_playlists')->where('id', $id)->delete() > 0;
    }

    public function updateSyncStatus(
        int $id,
        PlaylistSyncStatus $status,
        ?string $error = null,
        bool $touchSyncedAt = false,
    ): void {
        $data = [
            'last_sync_status' => $status->value,
            'last_sync_error' => $error,
            'updated_at' => now(),
        ];

        if ($touchSyncedAt) {
            $data['last_synced_at'] = now();
        }

        DB::table('saved_playlists')->where('id', $id)->update($data);
    }

    public function listDueForSync(): array
    {
        $rows = DB::table('saved_playlists')
            ->where('sync_enabled', true)
            ->where('last_sync_status', '!=', PlaylistSyncStatus::Running->value)
            ->get();

        $due = [];

        foreach ($rows as $row) {
            $playlist = $this->mapPlaylist((array) $row);
            $lastSynced = $playlist['last_synced_at'];

            if ($lastSynced === null) {
                $due[] = $playlist;

                continue;
            }

            $intervalMinutes = max(1, (int) $playlist['sync_interval_minutes']);
            $nextDue = \Carbon\Carbon::parse($lastSynced)->addMinutes($intervalMinutes);

            if ($nextDue->lte(now())) {
                $due[] = $playlist;
            }
        }

        return $due;
    }

    public function listTracks(int $playlistId): array
    {
        return DB::table('saved_playlist_tracks')
            ->where('saved_playlist_id', $playlistId)
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn ($row) => $this->mapTrack((array) $row))
            ->all();
    }

    public function findTrackByExternalId(int $playlistId, string $externalId): ?array
    {
        $row = DB::table('saved_playlist_tracks')
            ->where('saved_playlist_id', $playlistId)
            ->where('external_id', $externalId)
            ->first();

        return $row ? $this->mapTrack((array) $row) : null;
    }

    public function upsertTrack(
        int $playlistId,
        string $externalId,
        string $title,
        ?string $artist,
        int $position,
    ): array {
        $existing = $this->findTrackByExternalId($playlistId, $externalId);

        if ($existing !== null) {
            $status = (string) $existing['status'];
            // Re-queue previously skipped tracks that reappeared
            $newStatus = $status === PlaylistTrackStatus::Skipped->value
                ? PlaylistTrackStatus::Pending->value
                : $status;

            DB::table('saved_playlist_tracks')->where('id', $existing['id'])->update([
                'title' => $title,
                'artist' => $artist,
                'position' => $position,
                'status' => $newStatus,
                'updated_at' => now(),
            ]);

            $updated = $this->findTrackByExternalId($playlistId, $externalId);

            if ($updated === null) {
                throw new \RuntimeException('Failed to update playlist track.');
            }

            return $updated;
        }

        $id = (int) DB::table('saved_playlist_tracks')->insertGetId([
            'saved_playlist_id' => $playlistId,
            'external_id' => $externalId,
            'title' => $title,
            'artist' => $artist,
            'position' => $position,
            'status' => PlaylistTrackStatus::Pending->value,
            'file_path' => null,
            'download_job_id' => null,
            'last_error' => null,
            'first_seen_at' => now(),
            'downloaded_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('saved_playlist_tracks')->where('id', $id)->first();

        if ($row === null) {
            throw new \RuntimeException('Failed to create playlist track.');
        }

        return $this->mapTrack((array) $row);
    }

    public function updateTrackStatus(
        int $trackId,
        PlaylistTrackStatus $status,
        ?string $filePath = null,
        ?string $error = null,
        ?int $downloadJobId = null,
    ): void {
        $data = [
            'status' => $status->value,
            'last_error' => $error,
            'updated_at' => now(),
        ];

        if ($filePath !== null) {
            $data['file_path'] = $filePath;
        }

        if ($downloadJobId !== null) {
            $data['download_job_id'] = $downloadJobId;
        }

        if ($status === PlaylistTrackStatus::Downloaded) {
            $data['downloaded_at'] = now();
            $data['last_error'] = null;
        }

        DB::table('saved_playlist_tracks')->where('id', $trackId)->update($data);
    }

    public function markMissingTracksSkipped(int $playlistId, array $externalIdsSeen): void
    {
        $query = DB::table('saved_playlist_tracks')
            ->where('saved_playlist_id', $playlistId)
            ->whereNotIn('status', [
                PlaylistTrackStatus::Skipped->value,
            ]);

        if ($externalIdsSeen !== []) {
            $query->whereNotIn('external_id', $externalIdsSeen);
        }

        $query->update([
            'status' => PlaylistTrackStatus::Skipped->value,
            'updated_at' => now(),
        ]);
    }

    public function listPendingTracks(int $playlistId): array
    {
        return DB::table('saved_playlist_tracks')
            ->where('saved_playlist_id', $playlistId)
            ->where('status', PlaylistTrackStatus::Pending->value)
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn ($row) => $this->mapTrack((array) $row))
            ->all();
    }

    public function trackCounts(int $playlistId): array
    {
        $rows = DB::table('saved_playlist_tracks')
            ->select('status', DB::raw('count(*) as aggregate'))
            ->where('saved_playlist_id', $playlistId)
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->all();

        $downloaded = (int) ($rows[PlaylistTrackStatus::Downloaded->value] ?? 0);
        $pending = (int) ($rows[PlaylistTrackStatus::Pending->value] ?? 0);
        $failed = (int) ($rows[PlaylistTrackStatus::Failed->value] ?? 0);
        $skipped = (int) ($rows[PlaylistTrackStatus::Skipped->value] ?? 0);

        return [
            'total' => $downloaded + $pending + $failed + $skipped,
            'downloaded' => $downloaded,
            'pending' => $pending,
            'failed' => $failed,
            'skipped' => $skipped,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function mapPlaylist(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'provider' => (string) $row['provider'],
            'url' => (string) $row['url'],
            'title' => $row['title'],
            'sync_enabled' => (bool) $row['sync_enabled'],
            'sync_interval_minutes' => (int) $row['sync_interval_minutes'],
            'default_format' => $row['default_format'],
            'last_synced_at' => $row['last_synced_at'],
            'last_sync_status' => (string) $row['last_sync_status'],
            'last_sync_error' => $row['last_sync_error'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function mapTrack(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'saved_playlist_id' => (int) $row['saved_playlist_id'],
            'external_id' => (string) $row['external_id'],
            'title' => (string) $row['title'],
            'artist' => $row['artist'],
            'position' => (int) $row['position'],
            'status' => (string) $row['status'],
            'file_path' => $row['file_path'],
            'download_job_id' => $row['download_job_id'] !== null ? (int) $row['download_job_id'] : null,
            'last_error' => $row['last_error'],
            'first_seen_at' => $row['first_seen_at'],
            'downloaded_at' => $row['downloaded_at'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }
}
