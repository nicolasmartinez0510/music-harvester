<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\Metadata\AlbumFolderCoverWriter;
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

        $covers = new AlbumFolderCoverWriter;
        $handler = new ApplyTrackMetadataHandler($settings, $enricher, $writer, $covers);
        $track = new Track(title: 'Song', id: '1');
        $handler->handle('/tmp/a.mp3', $track, 'youtube_music', new MetadataEnrichContext);

        $settings = Mockery::mock(SettingsRepository::class);
        $settings->shouldReceive('get')->with('metadata_enrich_enabled')->andReturn('0');
        $handler = new ApplyTrackMetadataHandler($settings, $enricher, $writer, $covers);
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

        $handler = new ApplyTrackMetadataHandler($settings, $enricher, $writer, new AlbumFolderCoverWriter);
        $handler->handle('/tmp/a.flac', new Track(title: 'Song', id: '9'), 'deezer', new MetadataEnrichContext(arl: 'arl'));

        $this->addToAssertionCount(1);
    }

    public function test_writes_cover_jpg_beside_the_audio_when_the_album_folder_has_none(): void
    {
        $dir = storage_path('framework/testing/album-cover-'.uniqid('', true));
        $album = $dir.'/artist/discovery';
        mkdir($album, 0755, true);
        $audio = $album.'/01 - harder.flac';
        file_put_contents($audio, 'audio');

        $settings = Mockery::mock(SettingsRepository::class);
        $settings->shouldReceive('get')->andReturn(null);

        $metadata = new AudioFileMetadata(title: 'Harder', coverBytes: "\xFF\xD8\xFF\xD9");
        $enricher = Mockery::mock(TrackMetadataEnricher::class);
        $enricher->shouldReceive('supports')->with('deezer')->andReturn(true);
        $enricher->shouldReceive('enrich')->once()->andReturn($metadata);

        $writer = Mockery::mock(AudioTagWriter::class);
        $writer->shouldReceive('apply')->once();

        $handler = new ApplyTrackMetadataHandler($settings, $enricher, $writer, new AlbumFolderCoverWriter);
        $handler->handle($audio, new Track(title: 'Harder', id: '9'), 'deezer', new MetadataEnrichContext);

        $this->assertSame("\xFF\xD8\xFF\xD9", file_get_contents($album.'/cover.jpg'));

        file_put_contents($album.'/cover.jpg', 'keep');
        $enricher->shouldReceive('enrich')->once()->andReturn(new AudioFileMetadata(title: 'Harder', coverBytes: 'other'));
        $writer->shouldReceive('apply')->once();
        $handler->handle($audio, new Track(title: 'Harder', id: '9'), 'deezer', new MetadataEnrichContext);
        $this->assertSame('keep', file_get_contents($album.'/cover.jpg'));

        $this->rmTree($dir);
    }

    public function test_does_not_write_cover_jpg_in_the_playlist_root(): void
    {
        $dir = storage_path('framework/testing/album-cover-'.uniqid('', true));
        $playlist = $dir.'/playlists/5-mix';
        mkdir($playlist, 0755, true);
        $audio = $playlist.'/01 - artist - song.flac';
        file_put_contents($audio, 'audio');

        $settings = Mockery::mock(SettingsRepository::class);
        $settings->shouldReceive('get')->andReturn(null);

        $metadata = new AudioFileMetadata(title: 'Song', coverBytes: 'jpeg');
        $enricher = Mockery::mock(TrackMetadataEnricher::class);
        $enricher->shouldReceive('supports')->with('deezer')->andReturn(true);
        $enricher->shouldReceive('enrich')->once()->andReturn($metadata);

        $writer = Mockery::mock(AudioTagWriter::class);
        $writer->shouldReceive('apply')->once();

        $handler = new ApplyTrackMetadataHandler($settings, $enricher, $writer, new AlbumFolderCoverWriter);
        $handler->handle($audio, new Track(title: 'Song', id: '9'), 'deezer', new MetadataEnrichContext);

        $this->assertFileDoesNotExist($playlist.'/cover.jpg');
        $this->rmTree($dir);
    }

    private function rmTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.'/'.$item;
            if (is_dir($path)) {
                $this->rmTree($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
