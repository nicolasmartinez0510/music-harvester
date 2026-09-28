<?php

declare(strict_types=1);

namespace App\Domain\Music\Contracts;

use App\Domain\Music\ValueObjects\CatalogHit;

/**
 * Authenticated user library for a music provider (favorites / owned playlists).
 * Distinct from CatalogSource, which is public catalog search/browse.
 */
interface UserLibrarySource
{
    public function name(): string;

    /**
     * Whether credentials required for library access are configured.
     */
    public function isLibraryAvailable(): bool;

    /**
     * @return list<CatalogHit>
     */
    public function favoriteArtists(int $limit = 50, int $index = 0): array;

    /**
     * @return list<CatalogHit>
     */
    public function favoriteAlbums(int $limit = 50, int $index = 0): array;

    /**
     * @return list<CatalogHit>
     */
    public function lovedTracks(int $limit = 50, int $index = 0): array;

    /**
     * @return list<CatalogHit>
     */
    public function playlists(int $limit = 50, int $index = 0): array;
}
