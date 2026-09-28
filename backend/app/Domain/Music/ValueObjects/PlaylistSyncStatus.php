<?php

declare(strict_types=1);

namespace App\Domain\Music\ValueObjects;

enum PlaylistSyncStatus: string
{
    case Idle = 'idle';
    case Running = 'running';
    case Done = 'done';
    case Failed = 'failed';
}
