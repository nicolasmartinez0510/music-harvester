<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Music\Contracts\DownloadJobRepository;
use App\Domain\Music\Contracts\MusicProvider;
use App\Domain\Music\Models\Artist;
use App\Domain\Music\Models\Track;
use App\Domain\Music\ValueObjects\AudioFormat;
use App\Domain\Music\ValueObjects\DownloadResult;
use App\Domain\Music\ValueObjects\DownloadStatus;
use App\Domain\Music\ValueObjects\ResolvedItem;
use App\Domain\Music\ValueObjects\ResolvedKind;
use App\Domain\Music\ValueObjects\ResolvedMusic;
use App\Infrastructure\Providers\MusicProviderRegistry;
use App\Jobs\ProcessDownloadJob;
use Mockery;
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

        $provider = Mockery::mock(MusicProvider::class);
        $provider->shouldReceive('supports')->andReturn(true);
        $provider->shouldReceive('resolve')
            ->once()
            ->with('https://music.youtube.com/watch?v=vid123')
            ->andReturn($resolved);
        $provider->shouldReceive('download')
            ->once()
            ->andReturn(DownloadResult::ok('/music/artist/album/01 - song.mp3'));

        $registry = new MusicProviderRegistry([$provider]);

        $jobs = Mockery::mock(DownloadJobRepository::class);
        $jobs->shouldReceive('find')
            ->once()
            ->with(42)
            ->andReturn([
                'id' => 42,
                'url' => 'https://music.youtube.com/watch?v=vid123',
                'kind' => 'track',
                'status' => DownloadStatus::Pending->value,
                'options_json' => json_encode(['format' => AudioFormat::Mp3_320->value]),
            ]);
        $jobs->shouldReceive('updateStatus')
            ->once()
            ->with(42, DownloadStatus::Running);
        $jobs->shouldReceive('updateProgress')
            ->once()
            ->with(42, 100, '/music/artist/album/01 - song.mp3');
        $jobs->shouldReceive('updateStatus')
            ->once()
            ->with(42, DownloadStatus::Done, null);

        $job = new ProcessDownloadJob(42);
        $job->handle($jobs, $registry);

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

        $provider = Mockery::mock(MusicProvider::class);
        $provider->shouldReceive('supports')->andReturn(true);
        $provider->shouldReceive('resolve')->once()->andReturn($resolved);
        $provider->shouldReceive('download')
            ->once()
            ->andReturn(DownloadResult::failed('network error'));

        $registry = new MusicProviderRegistry([$provider]);

        $jobs = Mockery::mock(DownloadJobRepository::class);
        $jobs->shouldReceive('find')->once()->with(7)->andReturn([
            'id' => 7,
            'url' => 'https://music.youtube.com/watch?v=vid123',
            'kind' => 'track',
            'status' => DownloadStatus::Pending->value,
            'options_json' => json_encode(['format' => AudioFormat::Mp3_320->value]),
        ]);
        $jobs->shouldReceive('updateStatus')->once()->with(7, DownloadStatus::Running);
        $jobs->shouldReceive('updateStatus')
            ->once()
            ->with(7, DownloadStatus::Failed, Mockery::type('string'));

        $job = new ProcessDownloadJob(7);

        $this->expectException(\RuntimeException::class);
        $job->handle($jobs, $registry);
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

        $provider = Mockery::mock(MusicProvider::class);
        $provider->shouldReceive('supports')->andReturn(true);
        $provider->shouldReceive('resolve')->once()->andReturn($resolved);
        $provider->shouldReceive('download')
            ->once()
            ->andReturn(DownloadResult::ok('/music/artist/album/01 - song-one.mp3'));
        $provider->shouldReceive('download')
            ->once()
            ->andReturn(DownloadResult::failed('ERROR: Did not get any data blocks'));

        $registry = new MusicProviderRegistry([$provider]);

        $jobs = Mockery::mock(DownloadJobRepository::class);
        $jobs->shouldReceive('find')->once()->with(9)->andReturn([
            'id' => 9,
            'url' => 'https://music.youtube.com/playlist?list=PLtest',
            'kind' => 'playlist',
            'status' => DownloadStatus::Pending->value,
            'options_json' => json_encode(['format' => AudioFormat::Mp3_320->value]),
        ]);
        $jobs->shouldReceive('updateStatus')->once()->with(9, DownloadStatus::Running);
        $jobs->shouldReceive('updateProgress')->once()->with(9, 50, '/music/artist/album/01 - song-one.mp3');
        $jobs->shouldReceive('updateStatus')
            ->once()
            ->with(9, DownloadStatus::Done, Mockery::on(
                fn (string $summary) => str_contains($summary, 'Completed 1/2 tracks')
                    && str_contains($summary, 'Song Two')
            ));

        $job = new ProcessDownloadJob(9);
        $job->handle($jobs, $registry);

        $this->addToAssertionCount(1);
    }
}
