<?php

declare(strict_types=1);

namespace App\Application\Metadata;

use App\Domain\Music\Contracts\AudioTagWriter;
use App\Domain\Music\Contracts\SettingsRepository;
use App\Domain\Music\Contracts\TrackMetadataApplicator;
use App\Domain\Music\Contracts\TrackMetadataEnricher;
use App\Domain\Music\Models\Track;
use App\Domain\Music\ValueObjects\MetadataEnrichContext;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class ApplyTrackMetadataHandler implements TrackMetadataApplicator
{
    public function __construct(
        private SettingsRepository $settings,
        private TrackMetadataEnricher $enricher,
        private AudioTagWriter $writer,
        private AlbumFolderCoverWriter $covers,
    ) {}

    public function handle(string $filePath, Track $track, string $provider, MetadataEnrichContext $context): void
    {
        if (! $this->enricher->supports($provider)) {
            return;
        }

        if (! $this->flag('metadata_enrich_enabled', true)) {
            return;
        }

        if ($filePath === '' || $track->id === null || $track->id === '') {
            return;
        }

        $effective = new MetadataEnrichContext(
            arl: $context->arl,
            embedCover: $this->flag('metadata_embed_cover', true),
            embedLyrics: $this->flag('metadata_embed_lyrics', true),
            kind: $context->kind,
            trackTotal: $context->trackTotal,
        );

        try {
            $metadata = $this->enricher->enrich($track, $effective);
            $this->writer->apply($filePath, $metadata);
            $this->covers->writeIfMissing($filePath, $metadata->coverBytes);
        } catch (Throwable $exception) {
            Log::warning('audio metadata enrich failed', [
                'path' => $filePath,
                'provider' => $provider,
                'track_id' => $track->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function flag(string $key, bool $default): bool
    {
        $stored = $this->settings->get($key);
        if ($stored === null || $stored === '') {
            return (bool) config('music.'.$key, $default);
        }

        return ! in_array(strtolower($stored), ['0', 'false', 'off', 'no'], true);
    }
}
