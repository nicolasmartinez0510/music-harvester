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
     * Present file for this user with the same artist and title, ignoring provider and album.
     * A single hit is reused even when the year differs. Several hits are narrowed by release year.
     *
     * @return array<string, mixed>|null
     */
    public function findPresentByIdentity(?int $userId, string $artist, string $title, ?int $releaseYear): ?array;

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
        ?int $releaseYear = null,
    ): void;

    /**
     * @return list<array<string, mixed>>
     */
    public function listMissingReleaseYear(): array;

    public function setReleaseYear(int $id, int $releaseYear): void;

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
