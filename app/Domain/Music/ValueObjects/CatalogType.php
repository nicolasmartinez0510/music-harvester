<?php

declare(strict_types=1);

namespace App\Domain\Music\ValueObjects;

enum CatalogType: string
{
    case Track = 'track';
    case Album = 'album';
    case Artist = 'artist';
    case Playlist = 'playlist';
    case All = 'all';
}
