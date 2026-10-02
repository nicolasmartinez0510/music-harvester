<?php

declare(strict_types=1);

namespace App\Domain\Music\Contracts;

interface ArtistPortraitLookup
{
    public function portraitUrl(string $artistName): ?string;
}
