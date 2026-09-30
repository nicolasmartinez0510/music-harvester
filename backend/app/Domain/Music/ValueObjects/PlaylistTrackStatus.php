<?php

declare(strict_types=1);

namespace App\Domain\Music\ValueObjects;

enum PlaylistTrackStatus: string
{
    case Pending = 'pending';
    case Downloaded = 'downloaded';
    case Existing = 'existing';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
