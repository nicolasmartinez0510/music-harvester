<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CatalogApiTest extends TestCase
{
    use RefreshDatabase;

    protected bool $authenticate = true;

    public function test_search_returns_hits_for_deezer(): void
    {
        Http::fake([
            'api.deezer.com/search/track*' => Http::response([
                'data' => [
                    [
                        'id' => 3135556,
                        'title' => 'Harder Better Faster Stronger',
                        'type' => 'track',
                        'artist' => ['name' => 'Daft Punk'],
                        'album' => ['cover_medium' => 'https://example.com/cover.jpg'],
                        'preview' => 'https://cdns-preview.dzcdn.net/stream/c-3135556.mp3',
                    ],
                ],
                'total' => 1,
            ]),
        ]);

        $response = $this->getJson('/api/catalog/search?provider=deezer&q=daft+punk&type=track');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', '3135556')
            ->assertJsonPath('data.0.type', 'track')
            ->assertJsonPath('data.0.canonical_url', 'https://www.deezer.com/track/3135556')
            ->assertJsonPath('data.0.preview_url', 'https://cdns-preview.dzcdn.net/stream/c-3135556.mp3');
    }

    public function test_search_unknown_provider_returns_404(): void
    {
        $this->getJson('/api/catalog/search?provider=spotify&q=test')
            ->assertNotFound();
    }

    public function test_get_artist_returns_top_and_albums(): void
    {
        Http::fake([
            'api.deezer.com/artist/27' => Http::response([
                'id' => 27,
                'name' => 'Daft Punk',
                'picture_xl' => 'https://example.com/artist-xl.jpg',
                'picture_big' => 'https://example.com/artist-big.jpg',
                'picture_medium' => 'https://example.com/artist.jpg',
                'nb_fan' => 1000,
            ]),
            'api.deezer.com/artist/27/top*' => Http::response([
                'data' => [
                    [
                        'id' => 3135556,
                        'title' => 'Harder Better Faster Stronger',
                        'type' => 'track',
                        'artist' => ['name' => 'Daft Punk'],
                        'preview' => 'https://cdns-preview.dzcdn.net/stream/c-3135556.mp3',
                    ],
                ],
            ]),
            'api.deezer.com/artist/27/albums*' => Http::response([
                'data' => [
                    [
                        'id' => 302127,
                        'title' => 'Discovery',
                        'type' => 'album',
                        'nb_tracks' => 14,
                        'cover_medium' => 'https://example.com/album.jpg',
                        'release_date' => '2001-03-12',
                        'fans' => 900000,
                        'record_type' => 'album',
                    ],
                    [
                        'id' => 999001,
                        'title' => 'One More Time',
                        'type' => 'album',
                        'record_type' => 'single',
                    ],
                ],
            ]),
        ]);

        $this->getJson('/api/catalog/deezer/artists/27')
            ->assertOk()
            ->assertJsonPath('data.name', 'Daft Punk')
            ->assertJsonPath('data.cover_url', 'https://example.com/artist-xl.jpg')
            ->assertJsonPath('data.top_tracks.0.id', '3135556')
            ->assertJsonPath('data.albums.0.id', '302127')
            ->assertJsonPath('data.albums.0.release_date', '2001-03-12')
            ->assertJsonPath('data.albums.0.fans', 900000)
            ->assertJsonPath('data.albums.0.record_type', 'album')
            ->assertJsonPath('data.albums.1.record_type', 'single')
            ->assertJsonPath('data.top_tracks.0.record_type', null)
            ->assertJsonPath('data.top_tracks.0.preview_url', 'https://cdns-preview.dzcdn.net/stream/c-3135556.mp3');
    }

    public function test_get_artist_includes_wikipedia_description(): void
    {
        Http::fake([
            'api.deezer.com/artist/27' => Http::response([
                'id' => 27,
                'name' => 'Daft Punk',
                'picture_medium' => 'https://example.com/artist.jpg',
                'nb_fan' => 1000,
            ]),
            'api.deezer.com/artist/27/top*' => Http::response(['data' => []]),
            'api.deezer.com/artist/27/albums*' => Http::response(['data' => []]),
            'es.wikipedia.org/api/rest_v1/page/summary/*' => Http::response([
                'type' => 'standard',
                'extract' => 'Daft Punk fue un dúo francés de música electrónica.',
            ]),
        ]);

        $this->getJson('/api/catalog/deezer/artists/27')
            ->assertOk()
            ->assertJsonPath('data.description', 'Daft Punk fue un dúo francés de música electrónica.')
            ->assertJsonPath('data.cover_url', 'https://example.com/artist.jpg');
    }

    public function test_get_album_returns_tracks(): void
    {
        Http::fake([
            'api.deezer.com/album/302127' => Http::response([
                'id' => 302127,
                'title' => 'Discovery',
                'nb_tracks' => 1,
                'cover_medium' => 'https://example.com/album.jpg',
                'artist' => ['name' => 'Daft Punk'],
            ]),
            'api.deezer.com/album/302127/tracks*' => Http::response([
                'data' => [
                    [
                        'id' => 3135556,
                        'title' => 'Harder Better Faster Stronger',
                        'type' => 'track',
                        'artist' => ['name' => 'Daft Punk'],
                        'preview' => 'https://cdns-preview.dzcdn.net/stream/c-3135556.mp3',
                    ],
                ],
                'total' => 1,
            ]),
        ]);

        $this->getJson('/api/catalog/deezer/albums/302127')
            ->assertOk()
            ->assertJsonPath('data.title', 'Discovery')
            ->assertJsonPath('data.tracks.0.id', '3135556')
            ->assertJsonPath('data.tracks.0.preview_url', 'https://cdns-preview.dzcdn.net/stream/c-3135556.mp3')
            ->assertJsonPath('data.canonical_url', 'https://www.deezer.com/album/302127');
    }

    public function test_get_playlist_returns_tracks(): void
    {
        Http::fake([
            'api.deezer.com/playlist/908622995' => Http::response([
                'id' => 908622995,
                'title' => 'Hits',
                'nb_tracks' => 1,
                'picture_medium' => 'https://example.com/pl.jpg',
                'creator' => ['name' => 'Deezer'],
            ]),
            'api.deezer.com/playlist/908622995/tracks*' => Http::response([
                'data' => [
                    [
                        'id' => 3135556,
                        'title' => 'Harder Better Faster Stronger',
                        'type' => 'track',
                        'artist' => ['name' => 'Daft Punk'],
                        'preview' => '',
                    ],
                ],
                'total' => 1,
            ]),
        ]);

        $this->getJson('/api/catalog/deezer/playlists/908622995')
            ->assertOk()
            ->assertJsonPath('data.title', 'Hits')
            ->assertJsonPath('data.tracks.0.id', '3135556')
            ->assertJsonPath('data.tracks.0.preview_url', null);
    }

    public function test_search_artist_prefers_picture_xl(): void
    {
        Http::fake([
            'api.deezer.com/search/artist*' => Http::response([
                'data' => [
                    [
                        'id' => 27,
                        'name' => 'Daft Punk',
                        'type' => 'artist',
                        'picture_xl' => 'https://example.com/artist-xl.jpg',
                        'picture_medium' => 'https://example.com/artist.jpg',
                    ],
                ],
                'total' => 1,
            ]),
        ]);

        $this->getJson('/api/catalog/search?provider=deezer&q=daft+punk&type=artist')
            ->assertOk()
            ->assertJsonPath('data.0.cover_url', 'https://example.com/artist-xl.jpg');
    }
}
