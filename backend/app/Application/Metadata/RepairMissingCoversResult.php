<?php

declare(strict_types=1);

namespace App\Application\Metadata;

final readonly class RepairMissingCoversResult
{
    public function __construct(
        public int $scanned,
        public int $alreadyCovered,
        public int $missingFile,
        public int $unsupported,
        public int $repaired,
        public int $stillMissing,
        public int $failed,
    ) {}
}
