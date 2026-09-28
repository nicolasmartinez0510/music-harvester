<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LibraryApiTest extends TestCase
{
    use RefreshDatabase;

    private const FAKE_ARL = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    public function test_library_artists_requires_arl(): void
    {
        $this->getJson('/api/library/deezer/artists')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Library for provider [deezer] is not available. Configure credentials (e.g. Deezer ARL).');
    }

    public function test_library_unknown_provider_returns_404(): void
    {
        $this->getJson('/api/library/spotify/artists')
            ->assertNotFound();
    }

    public function test_library_artists_with_arl(): void
    {
        $this->seedArl();

        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_contains($url, 'auth.deezer.com/login/arl')) {
                return Http::response(['access_token' => 'test-token']);
            }

            if (preg_match('#api\.deezer\.com/user/me/artists#', $url)) {
                return Http::response([
                    'data' => [[
                        'id' => 27,
                        'name' => 'Daft Punk',
                        'type' => 'artist',
                        'picture_medium' => 'https://example.com/artist.jpg',
                    ]],
                    'total' => 1,
                ]);
            }

            if (preg_match('#api\.deezer\.com/user/me(\?|$)#', $url)) {
                return Http::response(['id' => 999, 'name' => 'Test User']);
            }

            return Http::response(['error' => ['message' => 'Unexpected URL: '.$url]], 500);
        });

        $response = $this->getJson('/api/library/deezer/artists');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', '27')
            ->assertJsonPath('data.0.type', 'artist')
            ->assertJsonPath('data.0.title', 'Daft Punk')
            ->assertJsonPath('data.0.canonical_url', 'https://www.deezer.com/artist/27');
    }

    public function test_library_tracks_albums_playlists(): void
    {
        $this->seedArl();

        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_contains($url, 'auth.deezer.com/login/arl')) {
                return Http::response(['access_token' => 'tok']);
            }

            if (preg_match('#api\.deezer\.com/user/me/tracks#', $url)) {
                return Http::response([
                    'data' => [[
                        'id' => 3135556,
                        'title' => 'Harder Better Faster Stronger',
                        'type' => 'track',
                        'artist' => ['name' => 'Daft Punk'],
                    ]],
                ]);
            }

            if (preg_match('#api\.deezer\.com/user/me/albums#', $url)) {
                return Http::response([
                    'data' => [[
                        'id' => 302127,
                        'title' => 'Discovery',
                        'type' => 'album',
                        'artist' => ['name' => 'Daft Punk'],
                        'cover_medium' => 'https://example.com/a.jpg',
                    ]],
                ]);
            }

            if (preg_match('#api\.deezer\.com/user/me/playlists#', $url)) {
                return Http::response([
                    'data' => [[
                        'id' => 908622995,
                        'title' => 'My Mix',
                        'type' => 'playlist',
                        'user' => ['name' => 'me'],
                        'nb_tracks' => 10,
                        'picture_medium' => 'https://example.com/p.jpg',
                    ]],
                ]);
            }

            if (preg_match('#api\.deezer\.com/user/me(\?|$)#', $url)) {
                return Http::response(['id' => 1, 'name' => 'me']);
            }

            return Http::response(['error' => ['message' => 'Unexpected URL: '.$url]], 500);
        });

        $this->getJson('/api/library/deezer/tracks')
            ->assertOk()
            ->assertJsonPath('data.0.type', 'track')
            ->assertJsonPath('data.0.id', '3135556');

        $this->getJson('/api/library/deezer/albums')
            ->assertOk()
            ->assertJsonPath('data.0.type', 'album')
            ->assertJsonPath('data.0.id', '302127');

        $this->getJson('/api/library/deezer/playlists')
            ->assertOk()
            ->assertJsonPath('data.0.type', 'playlist')
            ->assertJsonPath('data.0.id', '908622995');
    }

    public function test_library_falls_back_to_user_id_without_access_token(): void
    {
        $this->seedArl();

        Http::fake([
            'auth.deezer.com/login/arl*' => Http::response(['error' => 'denied'], 403),
            'www.deezer.com/ajax/gw-light.php*' => Http::response([
                'results' => ['USER' => ['USER_ID' => '4242'], 'checkForm' => 'x'],
            ]),
            'api.deezer.com/user/4242/artists*' => Http::response([
                'data' => [[
                    'id' => 1,
                    'name' => 'Artist',
                    'type' => 'artist',
                ]],
            ]),
        ]);

        $this->getJson('/api/library/deezer/artists')
            ->assertOk()
            ->assertJsonPath('data.0.id', '1');
    }

    private function seedArl(): void
    {
        DB::table('settings')->insert([
            'key' => 'provider_deezer_arl',
            'value' => self::FAKE_ARL,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
