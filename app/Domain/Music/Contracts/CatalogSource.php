<?php

declare(strict_types=1);

namespace App\Domain\Music\Contracts;

use App\Domain\Music\ValueObjects\CatalogAlbum;
use App\Domain\Music\ValueObjects\CatalogArtist;
use App\Domain\Music\ValueObjects\CatalogHit;
use App\Domain\Music\ValueObjects\CatalogPlaylist;
use App\Domain\Music\ValueObjects\CatalogType;

interface CatalogSource
{
    public function name(): string;

    /**
     * @return list<CatalogHit>
     */
    public function search(string $query, CatalogType $type, int $limit = 25, int $index = 0): array;

    public function getArtist(string $id): CatalogArtist;

    public function getAlbum(string $id): CatalogAlbum;

    public function getPlaylist(string $id): CatalogPlaylist;
}
