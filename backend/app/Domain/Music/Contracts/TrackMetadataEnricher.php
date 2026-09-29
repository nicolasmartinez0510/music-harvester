<?php

declare(strict_types=1);

namespace App\Domain\Music\Contracts;

use App\Domain\Music\Models\Track;
use App\Domain\Music\ValueObjects\AudioFileMetadata;
use App\Domain\Music\ValueObjects\MetadataEnrichContext;

interface TrackMetadataEnricher
{
    public function supports(string $provider): bool;

    public function enrich(Track $track, MetadataEnrichContext $context): AudioFileMetadata;
}
