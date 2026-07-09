<?php

declare(strict_types=1);

namespace App\Domain\Music\ValueObjects;

enum ResolvedKind: string
{
    case Track = 'track';
    case Album = 'album';
    case Playlist = 'playlist';
}
