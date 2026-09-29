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
use Illuminate\Support\Facades\Http;
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
            'https://cdn.example/cover.jpg' => Http::response('JPEG', 200, ['Content-Type' => 'image/jpeg']),
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
        $this->assertSame('JPEG', $first->coverBytes);
        $this->assertSame('image/jpeg', $first->coverMime);
        $this->assertSame('Work it', $first->lyricsPlain);
        $this->assertSame('One More Time', $second->title);
        $this->assertSame(14, $second->trackTotal);

        $albumCalls = 0;
        $lyricCalls = 0;
        foreach (Http::recorded() as [$request]) {
            if (str_contains($request->url(), '/album/302127')) {
                $albumCalls++;
            }
            $query = [];
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            if (($query['method'] ?? '') === 'song.getLyrics') {
                $lyricCalls++;
            }
        }
        $this->assertSame(1, $albumCalls);
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
}
