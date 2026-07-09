<?php

declare(strict_types=1);

namespace App\Domain\Music\ValueObjects;

enum DownloadStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Done = 'done';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Done, self::Failed => true,
            default => false,
        };
    }
}
