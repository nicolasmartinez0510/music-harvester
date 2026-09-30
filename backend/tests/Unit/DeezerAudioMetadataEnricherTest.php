<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Music\Models\Artist;
use App\Domain\Music\Models\Track;
use App\Domain\Music\ValueObjects\MetadataEnrichContext;
use App\Domain\Music\ValueObjects\ResolvedKind;
use App\Infrastructure\Metadata\Deezer\DeezerAudioMetadataEnricher;
use App\Infrastructure\Metadata\Deezer\DeezerMetadataMapper;
use App\Infrastructure\Providers\Deezer\DeezerApiClient;
use App\Infrastructure\Providers\Deezer\DeezerGwClient;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class DeezerAudioMetadataEnricherTest extends TestCase
{
    public function test_fetches_track_album_cover_and_lyrics_and_caches_album(): void
    {
        Http::fake([
            'https://api.deezer.com/track/3135556' => Http::response([
                'id' => 3135556,
                'title' => 'Harder Better Faster Stronger',
                'track_position' => 4,
                'artist' => ['name' => 'Daft Punk'],
                'album' => [
                    'id' => 302127,
                    'title' => 'Discovery',
                    'cover_xl' => 'https://cdn.example/cover.jpg',
                ],
            ]),
            'https://api.deezer.com/track/1' => Http::response([
                'id' => 1,
                'title' => 'One More Time',
                'track_position' => 1,
                'artist' => ['name' => 'Daft Punk'],
                'album' => [
                    'id' => 302127,
                    'title' => 'Discovery',
                    'cover_xl' => 'https://cdn.example/cover.jpg',
                ],
            ]),
            'https://api.deezer.com/album/302127' => Http::response([
                'id' => 302127,
                'title' => 'Discovery',
                'release_date' => '2001-03-12',
                'nb_tracks' => 14,
                'cover_xl' => 'https://cdn.example/cover.jpg',
                'artist' => ['name' => 'Daft Punk'],
                'genres' => ['data' => [['name' => 'Dance']]],
            ]),
            'https://cdn.example/cover.jpg' => Http::response("\xFF\xD8\xFF\xD9", 200, ['Content-Type' => 'image/jpeg']),
            'https://www.deezer.com/ajax/gw-light.php*' => function (Request $request) {
                $query = [];
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

                if (($query['method'] ?? '') === 'song.getLyrics') {
                    return Http::response([
                        'results' => [
                            'LYRICS_TEXT' => 'Work it',
                        ],
                    ]);
                }

                return Http::response([
                    'results' => ['checkForm' => 'token'],
                ]);
            },
        ]);

        $enricher = new DeezerAudioMetadataEnricher(
            new DeezerApiClient,
            new DeezerGwClient(new DeezerApiClient),
            new DeezerMetadataMapper,
        );
        $context = new MetadataEnrichContext(arl: 'arl-token', kind: ResolvedKind::Album);

        $first = $enricher->enrich(new Track(title: 'x', artist: new Artist('x'), id: '3135556'), $context);
        $second = $enricher->enrich(new Track(title: 'y', artist: new Artist('y'), id: '1'), $context);

        $this->assertSame('Discovery', $first->albumTitle);
        $this->assertSame('Dance', $first->genres[0]);
        $this->assertSame("\xFF\xD8\xFF\xD9", $first->coverBytes);
        $this->assertSame('image/jpeg', $first->coverMime);
        $this->assertSame('Work it', $first->lyricsPlain);
        $this->assertSame('One More Time', $second->title);
        $this->assertSame(14, $second->trackTotal);

        $albumCalls = 0;
        $lyricCalls = 0;
        $coverCalls = 0;
        foreach (Http::recorded() as [$request]) {
            if (str_contains($request->url(), '/album/302127')) {
                $albumCalls++;
            }
            if (str_contains($request->url(), 'cover.jpg')) {
                $coverCalls++;
            }
            $query = [];
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            if (($query['method'] ?? '') === 'song.getLyrics') {
                $lyricCalls++;
            }
        }
        $this->assertSame(1, $albumCalls);
        $this->assertSame(1, $coverCalls);
        $this->assertSame(2, $lyricCalls);
    }

    public function test_skips_lyrics_without_arl_and_cover_when_disabled(): void
    {
        Http::fake([
            'https://api.deezer.com/track/9' => Http::response([
                'id' => 9,
                'title' => 'Solo',
                'artist' => ['name' => 'Artist'],
                'album' => ['id' => 3, 'title' => 'Album', 'cover_xl' => 'https://cdn.example/cover.jpg'],
            ]),
            'https://api.deezer.com/album/3' => Http::response([
                'id' => 3,
                'title' => 'Album',
                'nb_tracks' => 1,
            ]),
        ]);

        $enricher = new DeezerAudioMetadataEnricher(
            new DeezerApiClient,
            new DeezerGwClient(new DeezerApiClient),
            new DeezerMetadataMapper,
        );
        $metadata = $enricher->enrich(
            new Track(title: 'Solo', id: '9'),
            new MetadataEnrichContext(embedCover: false, embedLyrics: true, kind: ResolvedKind::Track),
        );

        $this->assertNull($metadata->lyricsPlain);
        $this->assertNull($metadata->coverBytes);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'cover.jpg'));
    }

    public function test_retries_transient_cover_http_failure(): void
    {
        Http::fake([
            'https://api.deezer.com/track/7' => Http::response([
                'id' => 7,
                'title' => 'Track',
                'artist' => ['name' => 'Artist'],
                'album' => ['id' => 8, 'title' => 'Album', 'cover_xl' => 'https://cdn.example/cover.jpg'],
            ]),
            'https://api.deezer.com/album/8' => Http::response([
                'id' => 8,
                'title' => 'Album',
                'nb_tracks' => 2,
                'cover_xl' => 'https://cdn.example/cover.jpg',
            ]),
            'https://cdn.example/cover.jpg' => Http::sequence()
                ->push('no', 503)
                ->push("\xFF\xD8\xFF\xD9", 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $metadata = $this->enricher()->enrich(
            new Track(title: 'Track', id: '7'),
            new MetadataEnrichContext(kind: ResolvedKind::Track),
        );

        $this->assertSame("\xFF\xD8\xFF\xD9", $metadata->coverBytes);
        $this->assertSame(2, $this->recordedUrlCount('cover.jpg'));
    }

    public function test_rejects_non_image_cover_and_logs_failure(): void
    {
        $logged = null;
        Log::listen(function (MessageLogged $event) use (&$logged): void {
            if ($event->message === 'deezer cover fetch failed') {
                $logged = $event;
            }
        });

        Http::fake([
            'https://api.deezer.com/track/11' => Http::response([
                'id' => 11,
                'title' => 'Track',
                'artist' => ['name' => 'Artist'],
                'album' => ['id' => 12, 'title' => 'Album', 'cover_xl' => 'https://cdn.example/cover.jpg'],
            ]),
            'https://api.deezer.com/album/12' => Http::response([
                'id' => 12,
                'title' => 'Album',
                'cover_xl' => 'https://cdn.example/cover.jpg',
            ]),
            'https://cdn.example/cover.jpg' => Http::response('not-an-image', 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $metadata = $this->enricher()->enrich(
            new Track(title: 'Track', id: '11'),
            new MetadataEnrichContext(kind: ResolvedKind::Track),
        );

        $this->assertNull($metadata->coverBytes);
        $this->assertSame(1, $this->recordedUrlCount('cover.jpg'));
        $this->assertInstanceOf(MessageLogged::class, $logged);
        $this->assertSame('warning', $logged->level);
        $this->assertSame('not_image', $logged->context['reason'] ?? null);
        $this->assertSame('11', $logged->context['track_id'] ?? null);
        $this->assertSame(200, $logged->context['status'] ?? null);
    }

    public function test_does_not_cache_failed_album_lookup(): void
    {
        Http::fake([
            'https://api.deezer.com/track/21' => Http::response([
                'id' => 21,
                'title' => 'First',
                'artist' => ['name' => 'Artist'],
                'album' => ['id' => 22, 'title' => 'Thin', 'cover_xl' => 'https://cdn.example/cover.jpg'],
            ]),
            'https://api.deezer.com/track/22' => Http::response([
                'id' => 22,
                'title' => 'Second',
                'artist' => ['name' => 'Artist'],
                'album' => ['id' => 22, 'title' => 'Thin', 'cover_xl' => 'https://cdn.example/cover.jpg'],
            ]),
            'https://api.deezer.com/album/22' => Http::sequence()
                ->push('unavailable', 500)
                ->push([
                    'id' => 22,
                    'title' => 'Full Album',
                    'nb_tracks' => 9,
                    'cover_xl' => 'https://cdn.example/cover.jpg',
                    'genres' => ['data' => [['name' => 'Rock']]],
                ]),
            'https://cdn.example/cover.jpg' => Http::response("\xFF\xD8\xFF\xD9", 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $enricher = $this->enricher();
        $context = new MetadataEnrichContext(embedLyrics: false, kind: ResolvedKind::Album);
        $first = $enricher->enrich(new Track(title: 'First', id: '21'), $context);
        $second = $enricher->enrich(new Track(title: 'Second', id: '22'), $context);

        $this->assertSame('Thin', $first->albumTitle);
        $this->assertSame('Full Album', $second->albumTitle);
        $this->assertSame(9, $second->trackTotal);
        $this->assertSame(2, $this->recordedUrlCount('/album/22'));
        $this->assertSame(1, $this->recordedUrlCount('cover.jpg'));
    }

    private function enricher(): DeezerAudioMetadataEnricher
    {
        return new DeezerAudioMetadataEnricher(
            new DeezerApiClient,
            new DeezerGwClient(new DeezerApiClient),
            new DeezerMetadataMapper,
        );
    }

    private function recordedUrlCount(string $needle): int
    {
        $count = 0;
        foreach (Http::recorded() as [$request]) {
            if (str_contains($request->url(), $needle)) {
                $count++;
            }
        }

        return $count;
    }
}
