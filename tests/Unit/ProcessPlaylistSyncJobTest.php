<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\Settings\ProviderSettingsResolver;
use App\Domain\Music\Contracts\MusicProvider;
use App\Domain\Music\Contracts\SavedPlaylistRepository;
use App\Domain\Music\Contracts\SettingsRepository;
use App\Domain\Music\Models\Artist;
use App\Domain\Music\Models\Track;
use App\Domain\Music\ValueObjects\DownloadOptions;
use App\Domain\Music\ValueObjects\DownloadResult;
use App\Domain\Music\ValueObjects\PlaylistSyncStatus;
use App\Domain\Music\ValueObjects\PlaylistTrackStatus;
use App\Domain\Music\ValueObjects\ResolvedItem;
use App\Domain\Music\ValueObjects\ResolvedKind;
use App\Domain\Music\ValueObjects\ResolvedMusic;
use App\Infrastructure\Providers\MusicProviderRegistry;
use App\Infrastructure\Storage\LocalMusicStorage;
use App\Infrastructure\Storage\PlaylistM3uWriter;
use App\Jobs\ProcessPlaylistSyncJob;
use Mockery;
use Tests\TestCase;

class ProcessPlaylistSyncJobTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_sync_upserts_tracks_downloads_pending_and_marks_done(): void
    {
        $track = new Track(
            title: 'Song',
            artist: new Artist('Artist'),
            id: 'vid1',
            index: 1,
        );

        $resolved = new ResolvedMusic(
            provider: 'youtube_music',
            kind: ResolvedKind::Playlist,
            title: 'My List',
            items: [new ResolvedItem(ResolvedKind::Track, $track, 1)],
            sourceUrl: 'https://music.youtube.com/playlist?list=PLx',
        );

        $provider = $this->mockProvider();
        $provider->shouldReceive('resolve')->once()->andReturn($resolved);
        $provider->shouldReceive('download')
            ->once()
            ->withArgs(function (ResolvedItem $item, DownloadOptions $options) {
                return $options->targetDirectory !== null
                    && str_contains((string) $options->targetDirectory, '/playlists/5-my-list')
                    && $item->item instanceof Track
                    && $item->item->index === 1;
            })
            ->andReturn(DownloadResult::ok('/tmp/01 - artist - song.mp3'));

        $settings = $this->settingsResolver();
        $registry = $this->registry([$provider], $settings);
        $base = storage_path('framework/testing/sync-job-'.uniqid());
        $storage = new LocalMusicStorage($base);
        $m3u = new PlaylistM3uWriter($storage);

        $playlists = Mockery::mock(SavedPlaylistRepository::class);
        $playlists->shouldReceive('find')->once()->with(5)->andReturn([
            'id' => 5,
            'provider' => 'youtube_music',
            'url' => 'https://music.youtube.com/playlist?list=PLx',
            'title' => null,
            'default_format' => 'mp3_320',
            'last_sync_status' => PlaylistSyncStatus::Idle->value,
        ]);
        $playlists->shouldReceive('updateSyncStatus')
            ->once()
            ->with(5, PlaylistSyncStatus::Running);
        $playlists->shouldReceive('update')->once()->with(5, ['title' => 'My List']);
        $playlists->shouldReceive('find')->once()->with(5)->andReturn([
            'id' => 5,
            'provider' => 'youtube_music',
            'url' => 'https://music.youtube.com/playlist?list=PLx',
            'title' => 'My List',
            'default_format' => 'mp3_320',
            'last_sync_status' => PlaylistSyncStatus::Running->value,
        ]);
        $playlists->shouldReceive('upsertTrack')
            ->once()
            ->with(5, 'vid1', 'Song', 'Artist', 1)
            ->andReturn([
                'id' => 10,
                'external_id' => 'vid1',
                'status' => PlaylistTrackStatus::Pending->value,
            ]);
        $playlists->shouldReceive('markMissingTracksSkipped')->once()->with(5, ['vid1']);
        $playlists->shouldReceive('listPendingTracks')->once()->with(5)->andReturn([
            [
                'id' => 10,
                'external_id' => 'vid1',
                'title' => 'Song',
                'position' => 1,
                'status' => PlaylistTrackStatus::Pending->value,
            ],
        ]);
        $playlists->shouldReceive('updateTrackStatus')
            ->once()
            ->with(10, PlaylistTrackStatus::Downloaded, '/tmp/01 - artist - song.mp3');
        $playlists->shouldReceive('listTracks')->twice()->with(5)->andReturn([]);
        $playlists->shouldReceive('updateSyncStatus')
            ->once()
            ->with(5, PlaylistSyncStatus::Done, null, true);

        $job = new ProcessPlaylistSyncJob(5);
        $job->handle($playlists, $registry, $settings, $storage, $m3u);

        $this->assertFileExists($base.'/playlists/5-my-list/5-my-list.m3u');
        $this->rmTree($base);
    }

    public function test_sync_continues_when_individual_track_fails(): void
    {
        $trackA = new Track(title: 'A', artist: new Artist('X'), id: 'a', index: 1);
        $trackB = new Track(title: 'B', artist: new Artist('X'), id: 'b', index: 2);

        $resolved = new ResolvedMusic(
            provider: 'youtube_music',
            kind: ResolvedKind::Playlist,
            title: 'List',
            items: [
                new ResolvedItem(ResolvedKind::Track, $trackA, 1),
                new ResolvedItem(ResolvedKind::Track, $trackB, 2),
            ],
            sourceUrl: 'https://music.youtube.com/playlist?list=PLy',
        );

        $provider = $this->mockProvider();
        $provider->shouldReceive('resolve')->once()->andReturn($resolved);
        $provider->shouldReceive('download')
            ->once()
            ->andReturn(DownloadResult::failed('network'));
        $provider->shouldReceive('download')
            ->once()
            ->andReturn(DownloadResult::ok('/tmp/02 - x - b.mp3'));

        $settings = $this->settingsResolver();
        $registry = $this->registry([$provider], $settings);
        $base = storage_path('framework/testing/sync-job-'.uniqid());
        $storage = new LocalMusicStorage($base);
        $m3u = new PlaylistM3uWriter($storage);

        $playlists = Mockery::mock(SavedPlaylistRepository::class);
        $playlists->shouldReceive('find')->once()->andReturn([
            'id' => 7,
            'provider' => 'youtube_music',
            'url' => 'https://music.youtube.com/playlist?list=PLy',
            'title' => 'List',
            'default_format' => null,
            'last_sync_status' => PlaylistSyncStatus::Idle->value,
        ]);
        $playlists->shouldReceive('updateSyncStatus')->once()->with(7, PlaylistSyncStatus::Running);
        $playlists->shouldReceive('upsertTrack')->twice()->andReturnUsing(function (...$args) {
            return [
                'id' => $args[1] === 'a' ? 1 : 2,
                'external_id' => $args[1],
                'status' => PlaylistTrackStatus::Pending->value,
            ];
        });
        $playlists->shouldReceive('markMissingTracksSkipped')->once();
        $playlists->shouldReceive('listPendingTracks')->once()->andReturn([
            ['id' => 1, 'external_id' => 'a', 'title' => 'A', 'position' => 1, 'status' => 'pending'],
            ['id' => 2, 'external_id' => 'b', 'title' => 'B', 'position' => 2, 'status' => 'pending'],
        ]);
        $playlists->shouldReceive('updateTrackStatus')
            ->once()
            ->with(1, PlaylistTrackStatus::Failed, null, 'network');
        $playlists->shouldReceive('updateTrackStatus')
            ->once()
            ->with(2, PlaylistTrackStatus::Downloaded, '/tmp/02 - x - b.mp3');
        $playlists->shouldReceive('listTracks')->andReturn([]);
        $playlists->shouldReceive('updateSyncStatus')
            ->once()
            ->with(7, PlaylistSyncStatus::Done, null, true);

        $job = new ProcessPlaylistSyncJob(7);
        $job->handle($playlists, $registry, $settings, $storage, $m3u);

        $this->assertFileExists($base.'/playlists/7-list/7-list.m3u');
        $this->rmTree($base);
    }

    private function mockProvider(): MusicProvider&\Mockery\MockInterface
    {
        $provider = Mockery::mock(MusicProvider::class);
        $provider->shouldReceive('name')->andReturn('youtube_music');
        $provider->shouldReceive('supports')->andReturn(true);

        return $provider;
    }

    /**
     * @param  list<MusicProvider>  $providers
     */
    private function registry(array $providers, ProviderSettingsResolver $settings): MusicProviderRegistry
    {
        return new MusicProviderRegistry($providers, $settings);
    }

    private function settingsResolver(): ProviderSettingsResolver
    {
        $repo = Mockery::mock(SettingsRepository::class);
        $repo->shouldReceive('get')->andReturn(null);

        return new ProviderSettingsResolver($repo);
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
