<?php

declare(strict_types=1);

namespace App\Domain\Music\Contracts;

interface DownloadedTrackRepository
{
    /**
     * Row whose file still exists on disk. Stale rows (missing file) are removed.
     *
     * @return array<string, mixed>|null
     */
    public function findPresent(?int $userId, string $provider, string $externalId): ?array;

    /**
     * Index row as stored, without checking the file or deleting a stale path.
     *
     * @return array<string, mixed>|null
     */
    public function findRecorded(?int $userId, string $provider, string $externalId): ?array;

    public function upsert(
        ?int $userId,
        string $provider,
        string $externalId,
        string $filePath,
        ?string $title = null,
        ?string $artist = null,
        ?int $downloadJobId = null,
        ?int $savedPlaylistTrackId = null,
    ): void;

    /**
     * @param  list<string>  $paths
     */
    public function deleteByPaths(array $paths): void;

    public function deleteByJobId(int $downloadJobId): void;

    /**
     * @return list<array<string, mixed>>
     */
    public function listByProvider(string $provider): array;

    /**
     * True when some index row points at this path but is not owned by $downloadJobId.
     */
    public function isReferencedByAnotherOwner(string $filePath, int $downloadJobId): bool;
}
