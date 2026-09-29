<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\Metadata\ApplyTrackMetadataHandler;
use App\Domain\Music\Contracts\AudioTagWriter;
use App\Domain\Music\Contracts\SettingsRepository;
use App\Domain\Music\Contracts\TrackMetadataEnricher;
use App\Domain\Music\Models\Track;
use App\Domain\Music\ValueObjects\AudioFileMetadata;
use App\Domain\Music\ValueObjects\MetadataEnrichContext;
use Mockery;
use Tests\TestCase;

class ApplyTrackMetadataHandlerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_skips_non_deezer_and_disabled_flag(): void
    {
        $settings = Mockery::mock(SettingsRepository::class);
        $settings->shouldReceive('get')->andReturn(null);

        $enricher = Mockery::mock(TrackMetadataEnricher::class);
        $enricher->shouldReceive('supports')->with('youtube_music')->andReturn(false);
        $enricher->shouldReceive('supports')->with('deezer')->andReturn(true);
        $enricher->shouldNotReceive('enrich');

        $writer = Mockery::mock(AudioTagWriter::class);
        $writer->shouldNotReceive('apply');

        $handler = new ApplyTrackMetadataHandler($settings, $enricher, $writer);
        $track = new Track(title: 'Song', id: '1');
        $handler->handle('/tmp/a.mp3', $track, 'youtube_music', new MetadataEnrichContext);

        $settings = Mockery::mock(SettingsRepository::class);
        $settings->shouldReceive('get')->with('metadata_enrich_enabled')->andReturn('0');
        $handler = new ApplyTrackMetadataHandler($settings, $enricher, $writer);
        $handler->handle('/tmp/a.mp3', $track, 'deezer', new MetadataEnrichContext);

        $this->addToAssertionCount(1);
    }

    public function test_applies_enriched_metadata_and_swallows_writer_errors(): void
    {
        $settings = Mockery::mock(SettingsRepository::class);
        $settings->shouldReceive('get')->andReturn(null);

        $metadata = new AudioFileMetadata(title: 'Song');
        $enricher = Mockery::mock(TrackMetadataEnricher::class);
        $enricher->shouldReceive('supports')->with('deezer')->andReturn(true);
        $enricher->shouldReceive('enrich')->once()->andReturn($metadata);

        $writer = Mockery::mock(AudioTagWriter::class);
        $writer->shouldReceive('apply')->once()->with('/tmp/a.flac', $metadata)->andThrow(new \RuntimeException('boom'));

        $handler = new ApplyTrackMetadataHandler($settings, $enricher, $writer);
        $handler->handle('/tmp/a.flac', new Track(title: 'Song', id: '9'), 'deezer', new MetadataEnrichContext(arl: 'arl'));

        $this->addToAssertionCount(1);
    }
}
