<?php

declare(strict_types=1);

namespace App\Domain\Music\Contracts;

use App\Domain\Music\ValueObjects\AudioFileMetadata;

interface AudioTagWriter
{
    public function apply(string $filePath, AudioFileMetadata $metadata): void;
}
