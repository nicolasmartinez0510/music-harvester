<?php

declare(strict_types=1);

namespace App\Domain\Music\Contracts;

use App\Domain\Music\ValueObjects\AudioFormat;
use App\Domain\Music\ValueObjects\PlaylistSyncStatus;
use App\Domain\Music\ValueObjects\PlaylistTrackStatus;

interface SavedPlaylistRepository
{
    /**
     * @return array{id: int, provider: string, url: string, title: string|null, sync_enabled: bool, sync_interval_minutes: int, default_format: string|null, last_synced_at: string|null, last_sync_status: string, last_sync_error: string|null, created_at: string|null, updated_at: string|null}
     */
    public function create(
        string $provider,
        string $url,
        ?string $title = null,
        bool $syncEnabled = true,
        int $syncIntervalMinutes = 5,
        ?AudioFormat $defaultFormat = null,
        ?int $userId = null,
    ): array;

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array;

    /**
     * @return array<string, mixed>|null
     */
    public function findByUrl(string $url, ?int $userId = null): ?array;

    /**
     * @return list<array<string, mixed>>
     */
    public function listAll(?int $userId = null, bool $includeUnowned = false): array;

    /**
     * @param  array{sync_enabled?: bool, sync_interval_minutes?: int, default_format?: string|null, title?: string|null}  $attributes
     * @return array<string, mixed>|null
     */
    public function update(int $id, array $attributes): ?array;

    public function delete(int $id): bool;

    public function updateSyncStatus(
        int $id,
        PlaylistSyncStatus $status,
        ?string $error = null,
        bool $touchSyncedAt = false,
    ): void;

    /**
     * Playlists due for scheduled sync (enabled, not running, interval elapsed).
     *
     * @return list<array<string, mixed>>
     */
    public function listDueForSync(): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function listTracks(int $playlistId): array;

    /**
     * @return array<string, mixed>|null
     */
    public function findTrackByExternalId(int $playlistId, string $externalId): ?array;

    /**
     * Upsert a track; new tracks start as pending. Existing downloaded tracks keep status.
     *
     * @return array<string, mixed>
     */
    public function upsertTrack(
        int $playlistId,
        string $externalId,
        string $title,
        ?string $artist,
        int $position,
    ): array;

    public function updateTrackStatus(
        int $trackId,
        PlaylistTrackStatus $status,
        ?string $filePath = null,
        ?string $error = null,
        ?int $downloadJobId = null,
    ): void;

    /**
     * Mark tracks present in DB but missing from remote resolve as skipped.
     *
     * @param  list<string>  $externalIdsSeen
     */
    public function markMissingTracksSkipped(int $playlistId, array $externalIdsSeen): void;

    /**
     * @return list<array<string, mixed>>
     */
    public function listPendingTracks(int $playlistId): array;

    /**
     * @return array{total: int, downloaded: int, pending: int, failed: int, skipped: int}
     */
    public function trackCounts(int $playlistId): array;
}
