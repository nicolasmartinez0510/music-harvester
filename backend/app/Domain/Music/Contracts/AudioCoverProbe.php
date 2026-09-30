<?php

declare(strict_types=1);

namespace App\Domain\Music\Contracts;

interface AudioCoverProbe
{
    public function hasCover(string $filePath): bool;
}
