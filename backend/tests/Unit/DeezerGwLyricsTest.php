<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Infrastructure\Providers\Deezer\DeezerApiClient;
use App\Infrastructure\Providers\Deezer\DeezerGwClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DeezerGwLyricsTest extends TestCase
{
    public function test_track_lyrics_maps_plain_and_synced_lines(): void
    {
        Http::fake(function (Request $request) {
            $query = [];
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $method = $query['method'] ?? '';

            if ($method === 'song.getLyrics') {
                return Http::response([
                    'results' => [
                        'LYRICS_TEXT' => "Work it\nMake it",
                        'LYRICS_SYNC_JSON' => [
                            ['milliseconds' => 1500, 'line' => 'Work it'],
                            ['milliseconds' => 3200, 'line' => 'Make it'],
                        ],
                    ],
                ]);
            }

            return Http::response([
                'results' => [
                    'checkForm' => 'api-token',
                    'USER' => ['USER_ID' => '42'],
                ],
            ]);
        });

        $lyrics = (new DeezerGwClient(new DeezerApiClient))->trackLyrics('arl-value', '3135556');

        $this->assertNotNull($lyrics);
        $this->assertSame("Work it\nMake it", $lyrics->plain);
        $this->assertSame("[00:01.50]Work it\n[00:03.20]Make it", $lyrics->synced);

        Http::assertSent(function (Request $request): bool {
            $query = [];
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['method'] ?? '') === 'song.getLyrics'
                && ($query['api_token'] ?? '') === 'api-token'
                && $request->method() === 'POST';
        });
    }

    public function test_track_lyrics_returns_null_when_gateway_has_no_lyrics(): void
    {
        Http::fake(fn () => Http::response(['results' => []]));

        $lyrics = (new DeezerGwClient(new DeezerApiClient))->trackLyrics('arl-value', '1');

        $this->assertNull($lyrics);
    }
}
