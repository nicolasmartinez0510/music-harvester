<?php

declare(strict_types=1);

namespace App\Domain\Music\ValueObjects;

enum PlaylistCoverMode: string
{
    case Auto = 'auto';
    case Mosaic = 'mosaic';
    case Title = 'title';
    case Artist = 'artist';
    case Custom = 'custom';
}
