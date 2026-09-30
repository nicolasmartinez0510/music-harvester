<?php

declare(strict_types=1);

namespace App\Domain\Music\Contracts;

interface AudioReleaseYearProbe
{
    /**
     * Year from the file's date tag, or null when the tag is missing.
     */
    public function releaseYear(string $filePath): ?int;
}
