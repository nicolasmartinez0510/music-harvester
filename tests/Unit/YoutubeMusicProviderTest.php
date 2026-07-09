<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Music\Contracts\MusicDownloader;
use App\Domain\Music\Models\Album;
use App\Domain\Music\Models\Artist;
use App\Domain\Music\Models\Track;
use App\Domain\Music\ValueObjects\AudioFormat;
use App\Domain\Music\ValueObjects\DownloadOptions;
use App\Domain\Music\ValueObjects\DownloadResult;
use App\Domain\Music\ValueObjects\ResolvedItem;
use App\Domain\Music\ValueObjects\ResolvedKind;
use App\Infrastructure\Providers\YoutubeMusic\YoutubeMusicProvider;
use App\Infrastructure\Storage\LocalMusicStorage;
use Mockery;
use Tests\TestCase;

class YoutubeMusicProviderTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_resolve_single_track_from_metadata(): void
    {
        $downloader = Mockery::mock(MusicDownloader::class);
        $downloader->shouldReceive('fetchMetadata')
            ->once()
            ->with('https://music.youtube.com/watch?v=abc123', null)
            ->andReturn([
                '_type' => 'video',
                'id' => 'abc123',
                'title' => 'Test Song',
                'artist' => 'Test Artist',
                'album' => 'Test Album',
                'track' => 3,
            ]);

        $provider = new YoutubeMusicProvider(
            $downloader,
            new LocalMusicStorage(storage_path('framework/testing/music')),
        );

        $resolved = $provider->resolve('https://music.youtube.com/watch?v=abc123');

        $this->assertSame('youtube_music', $resolved->provider);
        $this->assertSame(ResolvedKind::Track, $resolved->kind);
        $this->assertSame('Test Song', $resolved->title);
        $this->assertCount(1, $resolved->items);
        $this->assertInstanceOf(Track::class, $resolved->items[0]->item);
        $this->assertSame('abc123', $resolved->items[0]->item->id);
        $this->assertSame(3, $resolved->items[0]->item->index);
    }

    public function test_resolve_playlist_entries(): void
    {
        $downloader = Mockery::mock(MusicDownloader::class);
        $downloader->shouldReceive('fetchMetadata')
            ->once()
            ->andReturn([
                '_type' => 'playlist',
                'title' => 'My Playlist',
                'entries' => [
                    [
                        'id' => 'one',
                        'title' => 'Song One',
                        'artist' => 'Artist A',
                        'playlist_index' => 1,
                    ],
                    [
                        'id' => 'two',
                        'title' => 'Song Two',
                        'artist' => 'Artist A',
                        'playlist_index' => 2,
                    ],
                ],
            ]);

        $provider = new YoutubeMusicProvider(
            $downloader,
            new LocalMusicStorage(storage_path('framework/testing/music')),
        );

        $resolved = $provider->resolve('https://music.youtube.com/playlist?list=PLtest');

        $this->assertSame(ResolvedKind::Playlist, $resolved->kind);
        $this->assertSame('My Playlist', $resolved->title);
        $this->assertCount(2, $resolved->items);
        $this->assertSame('one', $resolved->items[0]->item->id);
        $this->assertSame('two', $resolved->items[1]->item->id);
    }

    public function test_resolve_album_kind_for_browse_url(): void
    {
        $downloader = Mockery::mock(MusicDownloader::class);
        $downloader->shouldReceive('fetchMetadata')
            ->once()
            ->andReturn([
                '_type' => 'playlist',
                'title' => 'Album Title',
                'entries' => [
                    ['id' => 'track1', 'title' => 'Track 1', 'artist' => 'Artist'],
                ],
            ]);

        $provider = new YoutubeMusicProvider(
            $downloader,
            new LocalMusicStorage(storage_path('framework/testing/music')),
        );

        $resolved = $provider->resolve('https://music.youtube.com/browse/MPREb_test');

        $this->assertSame(ResolvedKind::Album, $resolved->kind);
    }

    public function test_download_delegates_to_yt_dlp_with_output_template(): void
    {
        $basePath = storage_path('framework/testing/music-download');
        @mkdir($basePath, 0755, true);

        $track = new Track(
            title: 'Test Song',
            artist: new Artist('Test Artist'),
            album: new Album('Test Album', new Artist('Test Artist')),
            index: 1,
            id: 'abc123',
        );

        $options = new DownloadOptions(
            format: AudioFormat::Mp3_320,
            musicPath: $basePath,
        );

        $downloader = Mockery::mock(MusicDownloader::class);
        $downloader->shouldReceive('download')
            ->once()
            ->withArgs(function (string $url, DownloadOptions $passedOptions, string $template) use ($options, $basePath) {
                return $url === 'https://music.youtube.com/watch?v=abc123'
                    && $passedOptions->format === $options->format
                    && str_contains($template, $basePath.'/test-artist/test-album/01 - test-song.%(ext)s');
            })
            ->andReturn(DownloadResult::ok($basePath.'/test-artist/test-album/01 - test-song.mp3'));

        $provider = new YoutubeMusicProvider(
            $downloader,
            new LocalMusicStorage($basePath),
        );

        $result = $provider->download(
            new ResolvedItem(ResolvedKind::Track, $track),
            $options,
        );

        $this->assertTrue($result->success);
        $this->assertStringEndsWith('01 - test-song.mp3', (string) $result->destinationPath);
    }
}
