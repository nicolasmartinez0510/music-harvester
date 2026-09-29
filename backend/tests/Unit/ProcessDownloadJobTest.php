<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\Settings\ProviderSettingsResolver;
use App\Domain\Music\Contracts\DownloadJobRepository;
use App\Domain\Music\Contracts\MusicProvider;
use App\Domain\Music\Contracts\SettingsRepository;
use App\Domain\Music\Contracts\TrackMetadataApplicator;
use App\Domain\Music\Models\Artist;
use App\Domain\Music\Models\Track;
use App\Domain\Music\ValueObjects\AudioFormat;
use App\Domain\Music\ValueObjects\DownloadResult;
use App\Domain\Music\ValueObjects\DownloadStatus;
use App\Domain\Music\ValueObjects\MetadataEnrichContext;
use App\Domain\Music\ValueObjects\ResolvedItem;
use App\Domain\Music\ValueObjects\ResolvedKind;
use App\Domain\Music\ValueObjects\ResolvedMusic;
use App\Infrastructure\Providers\MusicProviderRegistry;
use App\Jobs\ProcessDownloadJob;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class ProcessDownloadJobTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_processes_pending_job_and_marks_done(): void
    {
        $track = new Track(
            title: 'Song',
            artist: new Artist('Artist'),
            id: 'vid123',
            index: 1,
        );

        $resolved = new ResolvedMusic(
            provider: 'youtube_music',
            kind: ResolvedKind::Track,
            title: 'Song',
            items: [new ResolvedItem(ResolvedKind::Track, $track, 1)],
            sourceUrl: 'https://music.youtube.com/watch?v=vid123',
        );

        $provider = $this->mockProvider();
        $provider->shouldReceive('resolve')
            ->once()
            ->with('https://music.youtube.com/watch?v=vid123')
            ->andReturn($resolved);
        $provider->shouldReceive('download')
            ->once()
            ->andReturn(DownloadResult::ok('/music/artist/album/01 - song.mp3'));

        $registry = $this->registry([$provider]);
        $settings = $this->settingsResolver();

        $jobs = Mockery::mock(DownloadJobRepository::class);
        $jobs->shouldReceive('find')
            ->once()
            ->with(42)
            ->andReturn([
                'id' => 42,
                'provider' => 'youtube_music',
                'url' => 'https://music.youtube.com/watch?v=vid123',
                'kind' => 'track',
                'status' => DownloadStatus::Pending->value,
                'options_json' => json_encode(['format' => AudioFormat::Mp3_320->value]),
            ]);
        $jobs->shouldReceive('updateStatus')
            ->once()
            ->with(42, DownloadStatus::Running);
        $jobs->shouldReceive('updateMetadata')
            ->once()
            ->with(42, 'Song', 'Artist');
        $jobs->shouldReceive('updateProgress')
            ->once()
            ->with(42, 100, '/music/artist/album/01 - song.mp3');
        $jobs->shouldReceive('appendDownloadedPath')
            ->once()
            ->with(42, '/music/artist/album/01 - song.mp3');
        $jobs->shouldReceive('updateStatus')
            ->once()
            ->with(42, DownloadStatus::Done, null);

        $job = new ProcessDownloadJob(42);
        $job->handle($jobs, $registry, $settings, $this->metadata());

        $this->addToAssertionCount(1);
    }

    public function test_marks_job_failed_when_download_fails(): void
    {
        $track = new Track(title: 'Song', artist: new Artist('Artist'), id: 'vid123', index: 1);
        $resolved = new ResolvedMusic(
            provider: 'youtube_music',
            kind: ResolvedKind::Track,
            title: 'Song',
            items: [new ResolvedItem(ResolvedKind::Track, $track, 1)],
            sourceUrl: 'https://music.youtube.com/watch?v=vid123',
        );

        $provider = $this->mockProvider();
        $provider->shouldReceive('resolve')->once()->andReturn($resolved);
        $provider->shouldReceive('download')
            ->once()
            ->andReturn(DownloadResult::failed('network error'));

        $registry = $this->registry([$provider]);
        $settings = $this->settingsResolver();

        $jobs = Mockery::mock(DownloadJobRepository::class);
        $jobs->shouldReceive('find')->once()->with(7)->andReturn([
            'id' => 7,
            'provider' => 'youtube_music',
            'url' => 'https://music.youtube.com/watch?v=vid123',
            'kind' => 'track',
            'status' => DownloadStatus::Pending->value,
            'options_json' => json_encode(['format' => AudioFormat::Mp3_320->value]),
        ]);
        $jobs->shouldReceive('updateStatus')->once()->with(7, DownloadStatus::Running);
        $jobs->shouldReceive('updateMetadata')->once()->with(7, 'Song', 'Artist');
        $jobs->shouldReceive('updateStatus')
            ->once()
            ->with(7, DownloadStatus::Failed, Mockery::type('string'));

        $job = new ProcessDownloadJob(7);

        $this->expectException(\RuntimeException::class);
        $job->handle($jobs, $registry, $settings, $this->metadata());
    }

    public function test_playlist_continues_after_individual_track_failure(): void
    {
        $tracks = [
            new Track(title: 'Song One', artist: new Artist('Artist'), id: 'one', index: 1),
            new Track(title: 'Song Two', artist: new Artist('Artist'), id: 'two', index: 2),
        ];

        $resolved = new ResolvedMusic(
            provider: 'youtube_music',
            kind: ResolvedKind::Playlist,
            title: 'Playlist',
            items: [
                new ResolvedItem(ResolvedKind::Track, $tracks[0], 1),
                new ResolvedItem(ResolvedKind::Track, $tracks[1], 2),
            ],
            sourceUrl: 'https://music.youtube.com/playlist?list=PLtest',
        );

        $provider = $this->mockProvider();
        $provider->shouldReceive('resolve')->once()->andReturn($resolved);
        $provider->shouldReceive('download')
            ->once()
            ->andReturn(DownloadResult::ok('/music/artist/album/01 - song-one.mp3'));
        $provider->shouldReceive('download')
            ->once()
            ->andReturn(DownloadResult::failed('ERROR: Did not get any data blocks'));

        $registry = $this->registry([$provider]);
        $settings = $this->settingsResolver();

        $jobs = Mockery::mock(DownloadJobRepository::class);
        $jobs->shouldReceive('find')->once()->with(9)->andReturn([
            'id' => 9,
            'provider' => 'youtube_music',
            'url' => 'https://music.youtube.com/playlist?list=PLtest',
            'kind' => 'playlist',
            'status' => DownloadStatus::Pending->value,
            'options_json' => json_encode(['format' => AudioFormat::Mp3_320->value]),
        ]);
        $jobs->shouldReceive('updateStatus')->once()->with(9, DownloadStatus::Running);
        $jobs->shouldReceive('updateMetadata')->once()->with(9, 'Playlist', null);
        $jobs->shouldReceive('updateProgress')->once()->with(9, 50, '/music/artist/album/01 - song-one.mp3');
        $jobs->shouldReceive('appendDownloadedPath')->once()->with(9, '/music/artist/album/01 - song-one.mp3');
        $jobs->shouldReceive('updateStatus')
            ->once()
            ->with(9, DownloadStatus::Done, Mockery::on(
                fn (string $summary) => str_contains($summary, 'Completed 1/2 tracks')
                    && str_contains($summary, 'Song Two')
            ));

        $job = new ProcessDownloadJob(9);
        $job->handle($jobs, $registry, $settings, $this->metadata());

        $this->addToAssertionCount(1);
    }

    public function test_deezer_download_applies_metadata_and_ignores_tagging_errors(): void
    {
        $track = new Track(title: 'Song', artist: new Artist('Artist'), id: '99', index: 4);
        $resolved = new ResolvedMusic(
            provider: 'deezer',
            kind: ResolvedKind::Album,
            title: 'Album',
            items: [new ResolvedItem(ResolvedKind::Track, $track, 4)],
            sourceUrl: 'https://www.deezer.com/album/1',
        );

        $provider = Mockery::mock(MusicProvider::class);
        $provider->shouldReceive('name')->andReturn('deezer');
        $provider->shouldReceive('supports')->andReturn(true);
        $provider->shouldReceive('resolve')->once()->andReturn($resolved);
        $provider->shouldReceive('download')->once()->andReturn(DownloadResult::ok('/music/artist/album/04 - song.flac'));

        $metadata = Mockery::mock(TrackMetadataApplicator::class);
        $metadata->shouldReceive('handle')
            ->once()
            ->with(
                '/music/artist/album/04 - song.flac',
                Mockery::on(fn (mixed $item): bool => $item instanceof Track && $item->id === '99'),
                'deezer',
                Mockery::on(fn (mixed $context): bool => $context instanceof MetadataEnrichContext
                    && $context->kind === ResolvedKind::Album),
            )
            ->andThrow(new \RuntimeException('tagger down'));

        $jobs = Mockery::mock(DownloadJobRepository::class);
        $jobs->shouldReceive('find')->once()->with(3)->andReturn([
            'id' => 3,
            'provider' => 'deezer',
            'url' => 'https://www.deezer.com/album/1',
            'kind' => 'album',
            'status' => DownloadStatus::Pending->value,
            'options_json' => json_encode(['format' => AudioFormat::Flac->value]),
        ]);
        $jobs->shouldReceive('updateStatus')->once()->with(3, DownloadStatus::Running);
        $jobs->shouldReceive('updateMetadata')->once()->with(3, 'Album', 'Artist');
        $jobs->shouldReceive('updateProgress')->once()->with(3, 100, '/music/artist/album/04 - song.flac');
        $jobs->shouldReceive('appendDownloadedPath')->once();
        $jobs->shouldReceive('updateStatus')->once()->with(3, DownloadStatus::Done, null);

        $job = new ProcessDownloadJob(3);
        $job->handle($jobs, $this->registry([$provider]), $this->settingsResolver(), $metadata);

        $this->addToAssertionCount(1);
    }

    private function metadata(): TrackMetadataApplicator&MockInterface
    {
        $metadata = Mockery::mock(TrackMetadataApplicator::class);
        $metadata->shouldIgnoreMissing();

        return $metadata;
    }

    private function mockProvider(): MusicProvider&MockInterface
    {
        $provider = Mockery::mock(MusicProvider::class);
        $provider->shouldReceive('name')->andReturn('youtube_music');
        $provider->shouldReceive('supports')->andReturn(true);

        return $provider;
    }

    /**
     * @param  list<MusicProvider>  $providers
     */
    private function registry(array $providers): MusicProviderRegistry
    {
        return new MusicProviderRegistry($providers, $this->settingsResolver());
    }

    private function settingsResolver(): ProviderSettingsResolver
    {
        $repo = Mockery::mock(SettingsRepository::class);
        $repo->shouldReceive('get')->andReturn(null);

        return new ProviderSettingsResolver($repo);
    }
}
