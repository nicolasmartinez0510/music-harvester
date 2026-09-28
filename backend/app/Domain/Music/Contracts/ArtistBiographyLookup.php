<?php

declare(strict_types=1);

namespace App\Domain\Music\Contracts;

interface ArtistBiographyLookup
{
    /**
     * Returns a short plain-text artist biography, or null when none is found.
     */
    public function find(string $artistName): ?string;
}
