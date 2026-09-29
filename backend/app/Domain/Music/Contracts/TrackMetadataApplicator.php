<?php

declare(strict_types=1);

namespace App\Domain\Music\Contracts;

use App\Domain\Music\Models\Track;
use App\Domain\Music\ValueObjects\MetadataEnrichContext;

interface TrackMetadataApplicator
{
    public function handle(string $filePath, Track $track, string $provider, MetadataEnrichContext $context): void;
}
