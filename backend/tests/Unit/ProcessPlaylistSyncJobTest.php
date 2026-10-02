<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\IndexDownloadedTracks\DownloadedTrackLookup;
use App\Application\Settings\ProviderSettingsResolver;
use App\Domain\Music\Contracts\DownloadedTrackRepository;
use App\Domain\Music\Contracts\MusicProvider;
use App\Domain\Music\Contracts\SavedPlaylistRepository;
use App\Domain\Music\Contracts\SettingsRepository;
use App\Domain\Music\Contracts\TrackMetadataApplicator;
use App\Domain\Music\Models\Album;
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
use Mockery\MockInterface;
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
                return $options->targetDirectory === null
                    && str_contains($options->musicPath, '/playlists/5-my-list')
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
        $findCount = 0;
        $playlists->shouldReceive('find')->with(5)->andReturnUsing(function () use (&$findCount) {
            $findCount++;

            return [
                'id' => 5,
                'provider' => 'youtube_music',
                'url' => 'https://music.youtube.com/playlist?list=PLx',
                'title' => $findCount === 1 ? null : 'My List',
                'default_format' => 'mp3_320',
                'last_sync_status' => $findCount === 1
                    ? PlaylistSyncStatus::Idle->value
                    : PlaylistSyncStatus::Running->value,
            ];
        });
        $playlists->shouldReceive('updateSyncStatus')
            ->once()
            ->with(5, PlaylistSyncStatus::Running);
        $playlists->shouldReceive('update')->once()->with(5, ['title' => 'My List']);
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
        $playlists->shouldReceive('listTracks')->times(4)->with(5)->andReturn([]);
        $playlists->shouldReceive('updateSyncStatus')
            ->once()
            ->with(5, PlaylistSyncStatus::Done, null, true);

        $job = new ProcessPlaylistSyncJob(5);
        $job->handle($playlists, $registry, $settings, $storage, $m3u, $this->metadata(), $this->downloadedTracks());

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
        $playlists->shouldReceive('find')->andReturn([
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
        $job->handle($playlists, $registry, $settings, $storage, $m3u, $this->metadata(), $this->downloadedTracks());

        $this->assertFileExists($base.'/playlists/7-list/7-list.m3u');
        $this->rmTree($base);
    }

    public function test_sync_reuses_indexed_track_without_downloading(): void
    {
        $track = new Track(title: 'Song', artist: new Artist('Artist'), id: 'vid1', index: 1);
        $resolved = new ResolvedMusic(
            provider: 'deezer',
            kind: ResolvedKind::Playlist,
            title: 'My List',
            items: [new ResolvedItem(ResolvedKind::Track, $track, 1)],
            sourceUrl: 'https://www.deezer.com/playlist/1',
        );

        $provider = Mockery::mock(MusicProvider::class);
        $provider->shouldReceive('name')->andReturn('deezer');
        $provider->shouldReceive('supports')->andReturn(true);
        $provider->shouldReceive('resolve')->once()->andReturn($resolved);
        $provider->shouldReceive('download')->never();

        $settings = $this->settingsResolver();
        $registry = $this->registry([$provider], $settings);
        $base = storage_path('framework/testing/sync-job-'.uniqid());
        $storage = new LocalMusicStorage($base);
        $m3u = new PlaylistM3uWriter($storage);

        $playlists = Mockery::mock(SavedPlaylistRepository::class);
        $playlists->shouldReceive('find')->andReturn([
            'id' => 8,
            'provider' => 'deezer',
            'url' => 'https://www.deezer.com/playlist/1',
            'title' => 'My List',
            'default_format' => 'flac',
            'last_sync_status' => PlaylistSyncStatus::Idle->value,
        ]);
        $playlists->shouldReceive('updateSyncStatus')->once()->with(8, PlaylistSyncStatus::Running);
        $playlists->shouldReceive('upsertTrack')->once()->andReturn([
            'id' => 10,
            'external_id' => 'vid1',
            'status' => PlaylistTrackStatus::Pending->value,
        ]);
        $playlists->shouldReceive('markMissingTracksSkipped')->once();
        $playlists->shouldReceive('listPendingTracks')->once()->andReturn([
            [
                'id' => 10,
                'external_id' => 'vid1',
                'title' => 'Song',
                'artist' => 'Artist',
                'position' => 1,
                'status' => PlaylistTrackStatus::Pending->value,
            ],
        ]);
        $playlists->shouldReceive('updateTrackStatus')
            ->once()
            ->with(10, PlaylistTrackStatus::Existing, '/music/artist/album/01 - song.flac');
        $playlists->shouldReceive('listTracks')->andReturn([]);
        $playlists->shouldReceive('updateSyncStatus')
            ->once()
            ->with(8, PlaylistSyncStatus::Done, null, true);

        $index = Mockery::mock(DownloadedTrackRepository::class);
        $index->shouldReceive('findPresent')
            ->once()
            ->with(null, 'deezer', 'vid1')
            ->andReturn(['file_path' => '/music/artist/album/01 - song.flac']);
        $index->shouldReceive('upsert')->never();

        $job = new ProcessPlaylistSyncJob(8);
        $job->handle($playlists, $registry, $settings, $storage, $m3u, $this->metadata(), $index);

        $this->assertFileExists($base.'/playlists/8-my-list/8-my-list.m3u');
        $this->rmTree($base);
    }

    public function test_sync_reuses_same_artist_and_title_when_the_provider_id_differs(): void
    {
        $track = new Track(title: 'Vicious', artist: new Artist('Halestorm'), id: 'deluxe', index: 1, releaseYear: 2011);
        $resolved = new ResolvedMusic(
            provider: 'deezer',
            kind: ResolvedKind::Playlist,
            title: 'My List',
            items: [new ResolvedItem(ResolvedKind::Track, $track, 1)],
            sourceUrl: 'https://www.deezer.com/playlist/1',
        );

        $provider = Mockery::mock(MusicProvider::class);
        $provider->shouldReceive('name')->andReturn('deezer');
        $provider->shouldReceive('supports')->andReturn(true);
        $provider->shouldReceive('resolve')->once()->andReturn($resolved);
        $provider->shouldReceive('download')->never();

        $settings = $this->settingsResolver();
        $registry = $this->registry([$provider], $settings);
        $base = storage_path('framework/testing/sync-job-'.uniqid());
        $storage = new LocalMusicStorage($base);
        $m3u = new PlaylistM3uWriter($storage);

        $playlists = Mockery::mock(SavedPlaylistRepository::class);
        $playlists->shouldReceive('find')->andReturn([
            'id' => 11,
            'provider' => 'deezer',
            'url' => 'https://www.deezer.com/playlist/1',
            'title' => 'My List',
            'default_format' => 'flac',
            'last_sync_status' => PlaylistSyncStatus::Idle->value,
        ]);
        $playlists->shouldReceive('updateSyncStatus')->once()->with(11, PlaylistSyncStatus::Running);
        $playlists->shouldReceive('upsertTrack')->once()->andReturn([
            'id' => 20,
            'external_id' => 'deluxe',
            'status' => PlaylistTrackStatus::Pending->value,
        ]);
        $playlists->shouldReceive('markMissingTracksSkipped')->once();
        $playlists->shouldReceive('listPendingTracks')->once()->andReturn([
            [
                'id' => 20,
                'external_id' => 'deluxe',
                'title' => 'Vicious',
                'artist' => 'Halestorm',
                'position' => 1,
                'status' => PlaylistTrackStatus::Pending->value,
            ],
        ]);
        $playlists->shouldReceive('updateTrackStatus')
            ->once()
            ->with(20, PlaylistTrackStatus::Existing, '/music/halestorm/standard/01 - vicious.flac');
        $playlists->shouldReceive('listTracks')->andReturn([]);
        $playlists->shouldReceive('updateSyncStatus')
            ->once()
            ->with(11, PlaylistSyncStatus::Done, null, true);

        $index = Mockery::mock(DownloadedTrackRepository::class);
        $index->shouldReceive('findPresent')
            ->once()
            ->with(null, 'deezer', 'deluxe')
            ->andReturn(null);
        $index->shouldReceive('findPresentByIdentity')
            ->once()
            ->with(null, 'Halestorm', 'Vicious', 2011)
            ->andReturn(['file_path' => '/music/halestorm/standard/01 - vicious.flac']);
        $index->shouldReceive('upsert')->never();

        $job = new ProcessPlaylistSyncJob(11);
        $job->handle($playlists, $registry, $settings, $storage, $m3u, $this->metadata(), $index);

        $this->assertFileExists($base.'/playlists/11-my-list/11-my-list.m3u');
        $this->rmTree($base);
    }

    public function test_sync_without_provider_id_matches_by_artist_and_title(): void
    {
        $track = new Track(title: 'Vicious', artist: new Artist('Halestorm'), releaseYear: 2009);
        $resolved = new ResolvedMusic(
            provider: 'deezer',
            kind: ResolvedKind::Playlist,
            title: 'My List',
            items: [new ResolvedItem(ResolvedKind::Track, $track, 1)],
            sourceUrl: 'https://www.deezer.com/playlist/1',
        );
        $key = (new DownloadedTrackLookup())->indexId($track);

        $provider = Mockery::mock(MusicProvider::class);
        $provider->shouldReceive('name')->andReturn('deezer');
        $provider->shouldReceive('supports')->andReturn(true);
        $provider->shouldReceive('resolve')->once()->andReturn($resolved);
        $provider->shouldReceive('download')->never();

        $settings = $this->settingsResolver();
        $registry = $this->registry([$provider], $settings);
        $base = storage_path('framework/testing/sync-job-'.uniqid());
        $storage = new LocalMusicStorage($base);
        $m3u = new PlaylistM3uWriter($storage);

        $playlists = Mockery::mock(SavedPlaylistRepository::class);
        $playlists->shouldReceive('find')->andReturn([
            'id' => 13,
            'provider' => 'deezer',
            'url' => 'https://www.deezer.com/playlist/1',
            'title' => 'My List',
            'default_format' => 'flac',
            'last_sync_status' => PlaylistSyncStatus::Idle->value,
        ]);
        $playlists->shouldReceive('updateSyncStatus')->once()->with(13, PlaylistSyncStatus::Running);
        $playlists->shouldReceive('upsertTrack')->once()->with(13, $key, 'Vicious', 'Halestorm', 1)->andReturn([
            'id' => 30,
            'external_id' => $key,
            'status' => PlaylistTrackStatus::Pending->value,
        ]);
        $playlists->shouldReceive('markMissingTracksSkipped')->once()->with(13, [$key]);
        $playlists->shouldReceive('listPendingTracks')->once()->andReturn([
            [
                'id' => 30,
                'external_id' => $key,
                'title' => 'Vicious',
                'artist' => 'Halestorm',
                'position' => 1,
                'status' => PlaylistTrackStatus::Pending->value,
            ],
        ]);
        $playlists->shouldReceive('updateTrackStatus')
            ->once()
            ->with(30, PlaylistTrackStatus::Existing, '/music/halestorm/standard/01 - vicious.flac');
        $playlists->shouldReceive('listTracks')->andReturn([]);
        $playlists->shouldReceive('updateSyncStatus')
            ->once()
            ->with(13, PlaylistSyncStatus::Done, null, true);

        $index = Mockery::mock(DownloadedTrackRepository::class);
        $index->shouldReceive('findPresent')->never();
        $index->shouldReceive('findPresentByIdentity')
            ->once()
            ->with(null, 'Halestorm', 'Vicious', 2009)
            ->andReturn(['file_path' => '/music/halestorm/standard/01 - vicious.flac']);
        $index->shouldReceive('upsert')->never();

        $job = new ProcessPlaylistSyncJob(13);
        $job->handle($playlists, $registry, $settings, $storage, $m3u, $this->metadata(), $index);

        $this->assertFileExists($base.'/playlists/13-my-list/13-my-list.m3u');
        $this->rmTree($base);
    }

    public function test_sync_downloads_when_same_name_matches_only_other_years(): void
    {
        $track = new Track(title: 'Song', artist: new Artist('Artist'), id: 'vid1', index: 1, releaseYear: 2011);
        $resolved = new ResolvedMusic(
            provider: 'deezer',
            kind: ResolvedKind::Playlist,
            title: 'My List',
            items: [new ResolvedItem(ResolvedKind::Track, $track, 1)],
            sourceUrl: 'https://www.deezer.com/playlist/1',
        );

        $provider = Mockery::mock(MusicProvider::class);
        $provider->shouldReceive('name')->andReturn('deezer');
        $provider->shouldReceive('supports')->andReturn(true);
        $provider->shouldReceive('resolve')->once()->andReturn($resolved);
        $provider->shouldReceive('download')
            ->once()
            ->andReturn(DownloadResult::ok('/music/artist/album/01 - song.flac'));

        $settings = $this->settingsResolver();
        $registry = $this->registry([$provider], $settings);
        $base = storage_path('framework/testing/sync-job-'.uniqid());
        $storage = new LocalMusicStorage($base);
        $m3u = new PlaylistM3uWriter($storage);

        $playlists = Mockery::mock(SavedPlaylistRepository::class);
        $playlists->shouldReceive('find')->andReturn([
            'id' => 12,
            'provider' => 'deezer',
            'url' => 'https://www.deezer.com/playlist/1',
            'title' => 'My List',
            'default_format' => 'flac',
            'last_sync_status' => PlaylistSyncStatus::Idle->value,
        ]);
        $playlists->shouldReceive('updateSyncStatus')->once()->with(12, PlaylistSyncStatus::Running);
        $playlists->shouldReceive('upsertTrack')->once()->andReturn([
            'id' => 21,
            'external_id' => 'vid1',
            'status' => PlaylistTrackStatus::Pending->value,
        ]);
        $playlists->shouldReceive('markMissingTracksSkipped')->once();
        $playlists->shouldReceive('listPendingTracks')->once()->andReturn([
            [
                'id' => 21,
                'external_id' => 'vid1',
                'title' => 'Song',
                'artist' => 'Artist',
                'position' => 1,
                'status' => PlaylistTrackStatus::Pending->value,
            ],
        ]);
        $playlists->shouldReceive('updateTrackStatus')
            ->once()
            ->with(21, PlaylistTrackStatus::Downloaded, '/music/artist/album/01 - song.flac');
        $playlists->shouldReceive('listTracks')->andReturn([]);
        $playlists->shouldReceive('updateSyncStatus')
            ->once()
            ->with(12, PlaylistSyncStatus::Done, null, true);

        $index = Mockery::mock(DownloadedTrackRepository::class);
        $index->shouldReceive('findPresent')->once()->with(null, 'deezer', 'vid1')->andReturn(null);
        $index->shouldReceive('findPresentByIdentity')
            ->once()
            ->with(null, 'Artist', 'Song', 2011)
            ->andReturn(null);
        $index->shouldReceive('upsert')
            ->once()
            ->with(null, 'deezer', 'vid1', '/music/artist/album/01 - song.flac', 'Song', 'Artist', null, 21, 2011);

        $job = new ProcessPlaylistSyncJob(12);
        $job->handle($playlists, $registry, $settings, $storage, $m3u, $this->metadata(), $index);

        $this->assertFileExists($base.'/playlists/12-my-list/12-my-list.m3u');
        $this->rmTree($base);
    }

    public function test_sync_relabels_downloaded_tracks_that_live_outside_the_playlist_folder(): void
    {
        $track = new Track(title: 'Song', artist: new Artist('Artist'), id: 'vid1', index: 1);
        $resolved = new ResolvedMusic(
            provider: 'deezer',
            kind: ResolvedKind::Playlist,
            title: 'My List',
            items: [new ResolvedItem(ResolvedKind::Track, $track, 1)],
            sourceUrl: 'https://www.deezer.com/playlist/1',
        );

        $provider = Mockery::mock(MusicProvider::class);
        $provider->shouldReceive('name')->andReturn('deezer');
        $provider->shouldReceive('supports')->andReturn(true);
        $provider->shouldReceive('resolve')->once()->andReturn($resolved);
        $provider->shouldReceive('download')->never();

        $settings = $this->settingsResolver();
        $registry = $this->registry([$provider], $settings);
        $base = storage_path('framework/testing/sync-job-'.uniqid());
        $storage = new LocalMusicStorage($base);
        $m3u = new PlaylistM3uWriter($storage);

        $playlists = Mockery::mock(SavedPlaylistRepository::class);
        $playlists->shouldReceive('find')->andReturn([
            'id' => 9,
            'provider' => 'deezer',
            'url' => 'https://www.deezer.com/playlist/1',
            'title' => 'My List',
            'default_format' => 'flac',
            'last_sync_status' => PlaylistSyncStatus::Idle->value,
        ]);
        $playlists->shouldReceive('updateSyncStatus')->once()->with(9, PlaylistSyncStatus::Running);
        $playlists->shouldReceive('upsertTrack')->once()->andReturn([
            'id' => 10,
            'external_id' => 'vid1',
            'status' => PlaylistTrackStatus::Downloaded->value,
        ]);
        $playlists->shouldReceive('markMissingTracksSkipped')->once();
        $playlists->shouldReceive('listTracks')->andReturn([
            [
                'id' => 10,
                'external_id' => 'vid1',
                'title' => 'Song',
                'artist' => 'Artist',
                'status' => PlaylistTrackStatus::Downloaded->value,
                'file_path' => '/music/artist/album/01 - song.flac',
                'position' => 1,
            ],
        ]);
        $playlists->shouldReceive('listPendingTracks')->once()->andReturn([]);
        $playlists->shouldReceive('updateTrackStatus')
            ->once()
            ->with(10, PlaylistTrackStatus::Existing, '/music/artist/album/01 - song.flac');
        $playlists->shouldReceive('updateSyncStatus')
            ->once()
            ->with(9, PlaylistSyncStatus::Done, null, true);

        $job = new ProcessPlaylistSyncJob(9);
        $job->handle($playlists, $registry, $settings, $storage, $m3u, $this->metadata(), $this->downloadedTracks());

        $this->assertFileExists($base.'/playlists/9-my-list/9-my-list.m3u');
        $this->rmTree($base);
    }

    public function test_sync_moves_flat_playlist_audio_into_the_album_folder(): void
    {
        $track = new Track(
            title: 'Song',
            artist: new Artist('Artist'),
            album: new Album('Discovery'),
            id: 'vid1',
            index: 3,
        );
        $resolved = new ResolvedMusic(
            provider: 'deezer',
            kind: ResolvedKind::Playlist,
            title: 'My List',
            items: [new ResolvedItem(ResolvedKind::Track, $track, 7)],
            sourceUrl: 'https://www.deezer.com/playlist/1',
        );

        $provider = Mockery::mock(MusicProvider::class);
        $provider->shouldReceive('name')->andReturn('deezer');
        $provider->shouldReceive('supports')->andReturn(true);
        $provider->shouldReceive('resolve')->once()->andReturn($resolved);
        $provider->shouldReceive('download')->never();

        $settings = $this->settingsResolver();
        $registry = $this->registry([$provider], $settings);
        $base = storage_path('framework/testing/sync-job-'.uniqid());
        $storage = new LocalMusicStorage($base);
        $playlistDir = $base.'/playlists/9-my-list';
        mkdir($playlistDir, 0755, true);
        $flat = $playlistDir.'/03 - artist - song.flac';
        file_put_contents($flat, 'audio');
        $destination = $playlistDir.'/artist/discovery/03 - song.flac';

        $playlists = Mockery::mock(SavedPlaylistRepository::class);
        $playlists->shouldReceive('find')->andReturn([
            'id' => 9,
            'provider' => 'deezer',
            'url' => 'https://www.deezer.com/playlist/1',
            'title' => 'My List',
            'default_format' => 'flac',
            'last_sync_status' => PlaylistSyncStatus::Idle->value,
        ]);
        $playlists->shouldReceive('updateSyncStatus')->once()->with(9, PlaylistSyncStatus::Running);
        $playlists->shouldReceive('upsertTrack')->once()->andReturn([
            'id' => 10,
            'external_id' => 'vid1',
            'status' => PlaylistTrackStatus::Downloaded->value,
        ]);
        $playlists->shouldReceive('markMissingTracksSkipped')->once();
        $playlists->shouldReceive('listTracks')->andReturn([
            [
                'id' => 10,
                'external_id' => 'vid1',
                'title' => 'Song',
                'artist' => 'Artist',
                'status' => PlaylistTrackStatus::Downloaded->value,
                'file_path' => $flat,
                'position' => 7,
            ],
        ]);
        $playlists->shouldReceive('listPendingTracks')->once()->andReturn([]);
        $playlists->shouldReceive('updateTrackStatus')
            ->once()
            ->with(10, PlaylistTrackStatus::Downloaded, $destination);
        $playlists->shouldReceive('updateSyncStatus')
            ->once()
            ->with(9, PlaylistSyncStatus::Done, null, true);

        $index = Mockery::mock(DownloadedTrackRepository::class);
        $index->shouldReceive('replaceFilePath')->once()->with($flat, $destination);

        $metadata = Mockery::mock(TrackMetadataApplicator::class);
        $metadata->shouldReceive('handle')->once()->withArgs(
            function (string $path) use ($destination): bool {
                return $path === $destination;
            },
        );

        $job = new ProcessPlaylistSyncJob(9);
        $job->handle($playlists, $registry, $settings, $storage, new PlaylistM3uWriter($storage), $metadata, $index);

        $this->assertFileDoesNotExist($flat);
        $this->assertFileExists($destination);
        $this->assertFileDoesNotExist($playlistDir.'/cover.jpg');
        $this->rmTree($base);
    }

    public function test_sync_fetches_album_cover_for_tracks_already_inside_the_playlist(): void
    {
        $track = new Track(
            title: 'Song',
            artist: new Artist('Artist'),
            album: new Album('Discovery'),
            id: 'vid1',
            index: 3,
        );
        $resolved = new ResolvedMusic(
            provider: 'deezer',
            kind: ResolvedKind::Playlist,
            title: 'My List',
            items: [new ResolvedItem(ResolvedKind::Track, $track, 7)],
            sourceUrl: 'https://www.deezer.com/playlist/1',
        );

        $provider = Mockery::mock(MusicProvider::class);
        $provider->shouldReceive('name')->andReturn('deezer');
        $provider->shouldReceive('supports')->andReturn(true);
        $provider->shouldReceive('resolve')->once()->andReturn($resolved);
        $provider->shouldReceive('download')->never();

        $settings = $this->settingsResolver();
        $registry = $this->registry([$provider], $settings);
        $base = storage_path('framework/testing/sync-job-'.uniqid());
        $storage = new LocalMusicStorage($base);
        $audio = $base.'/playlists/9-my-list/artist/discovery/03 - song.flac';
        mkdir(dirname($audio), 0755, true);
        file_put_contents($audio, 'audio');

        $playlists = Mockery::mock(SavedPlaylistRepository::class);
        $playlists->shouldReceive('find')->andReturn([
            'id' => 9,
            'provider' => 'deezer',
            'url' => 'https://www.deezer.com/playlist/1',
            'title' => 'My List',
            'default_format' => 'flac',
            'last_sync_status' => PlaylistSyncStatus::Idle->value,
        ]);
        $playlists->shouldReceive('updateSyncStatus')->once()->with(9, PlaylistSyncStatus::Running);
        $playlists->shouldReceive('upsertTrack')->once()->andReturn([
            'id' => 10,
            'external_id' => 'vid1',
            'status' => PlaylistTrackStatus::Downloaded->value,
        ]);
        $playlists->shouldReceive('markMissingTracksSkipped')->once();
        $playlists->shouldReceive('listTracks')->andReturn([
            [
                'id' => 10,
                'external_id' => 'vid1',
                'title' => 'Song',
                'artist' => 'Artist',
                'status' => PlaylistTrackStatus::Downloaded->value,
                'file_path' => $audio,
                'position' => 7,
            ],
        ]);
        $playlists->shouldReceive('listPendingTracks')->once()->andReturn([]);
        $playlists->shouldReceive('updateSyncStatus')
            ->once()
            ->with(9, PlaylistSyncStatus::Done, null, true);

        $metadata = Mockery::mock(TrackMetadataApplicator::class);
        $metadata->shouldReceive('handle')->once()->withArgs(
            function (string $path) use ($audio): bool {
                return $path === $audio;
            },
        );

        $index = Mockery::mock(DownloadedTrackRepository::class);
        $index->shouldNotReceive('replaceFilePath');

        $job = new ProcessPlaylistSyncJob(9);
        $job->handle($playlists, $registry, $settings, $storage, new PlaylistM3uWriter($storage), $metadata, $index);

        $this->assertFileExists($audio);
        $this->rmTree($base);
    }

    public function test_sync_leaves_reused_library_files_outside_the_playlist_folder(): void
    {
        $track = new Track(
            title: 'Song',
            artist: new Artist('Artist'),
            album: new Album('Discovery'),
            id: 'deluxe',
            index: 1,
            releaseYear: 2011,
        );
        $resolved = new ResolvedMusic(
            provider: 'deezer',
            kind: ResolvedKind::Playlist,
            title: 'My List',
            items: [new ResolvedItem(ResolvedKind::Track, $track, 1)],
            sourceUrl: 'https://www.deezer.com/playlist/1',
        );

        $provider = Mockery::mock(MusicProvider::class);
        $provider->shouldReceive('name')->andReturn('deezer');
        $provider->shouldReceive('supports')->andReturn(true);
        $provider->shouldReceive('resolve')->once()->andReturn($resolved);
        $provider->shouldReceive('download')->never();

        $settings = $this->settingsResolver();
        $registry = $this->registry([$provider], $settings);
        $base = storage_path('framework/testing/sync-job-'.uniqid());
        $storage = new LocalMusicStorage($base);
        $libraryFile = $base.'/halestorm/standard/01 - song.flac';
        mkdir(dirname($libraryFile), 0755, true);
        file_put_contents($libraryFile, 'audio');

        $playlists = Mockery::mock(SavedPlaylistRepository::class);
        $playlists->shouldReceive('find')->andReturn([
            'id' => 9,
            'provider' => 'deezer',
            'url' => 'https://www.deezer.com/playlist/1',
            'title' => 'My List',
            'default_format' => 'flac',
            'last_sync_status' => PlaylistSyncStatus::Idle->value,
        ]);
        $playlists->shouldReceive('updateSyncStatus')->once()->with(9, PlaylistSyncStatus::Running);
        $playlists->shouldReceive('upsertTrack')->once()->andReturn([
            'id' => 10,
            'external_id' => 'deluxe',
            'status' => PlaylistTrackStatus::Pending->value,
        ]);
        $playlists->shouldReceive('markMissingTracksSkipped')->once();
        $playlists->shouldReceive('listTracks')->andReturn([
            [
                'id' => 10,
                'external_id' => 'deluxe',
                'title' => 'Song',
                'artist' => 'Artist',
                'status' => PlaylistTrackStatus::Existing->value,
                'file_path' => $libraryFile,
                'position' => 1,
            ],
        ]);
        $playlists->shouldReceive('listPendingTracks')->once()->andReturn([
            [
                'id' => 10,
                'external_id' => 'deluxe',
                'title' => 'Song',
                'artist' => 'Artist',
                'status' => PlaylistTrackStatus::Pending->value,
                'position' => 1,
            ],
        ]);
        $playlists->shouldReceive('updateTrackStatus')
            ->once()
            ->with(10, PlaylistTrackStatus::Existing, $libraryFile);
        $playlists->shouldReceive('updateSyncStatus')
            ->once()
            ->with(9, PlaylistSyncStatus::Done, null, true);

        $index = Mockery::mock(DownloadedTrackRepository::class);
        $index->shouldReceive('findPresent')->once()->andReturn(null);
        $index->shouldReceive('findPresentByIdentity')->once()->andReturn([
            'file_path' => $libraryFile,
        ]);
        $index->shouldNotReceive('upsert');
        $index->shouldNotReceive('replaceFilePath');

        $job = new ProcessPlaylistSyncJob(9);
        $job->handle($playlists, $registry, $settings, $storage, new PlaylistM3uWriter($storage), $this->metadata(), $index);

        $this->assertFileExists($libraryFile);
        $m3u = file_get_contents($base.'/playlists/9-my-list/9-my-list.m3u');
        $this->assertIsString($m3u);
        $this->assertStringContainsString('../', $m3u);
        $this->assertStringContainsString('halestorm/standard/01 - song.flac', $m3u);
        $this->rmTree($base);
    }

    private function downloadedTracks(): DownloadedTrackRepository&MockInterface
    {
        $tracks = Mockery::mock(DownloadedTrackRepository::class);
        $tracks->shouldReceive('findPresent')->andReturn(null);
        $tracks->shouldReceive('findPresentByIdentity')->andReturn(null);
        $tracks->shouldReceive('upsert');

        return $tracks;
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

    private function metadata(): TrackMetadataApplicator&MockInterface
    {
        $metadata = Mockery::mock(TrackMetadataApplicator::class);
        $metadata->shouldIgnoreMissing();

        return $metadata;
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
