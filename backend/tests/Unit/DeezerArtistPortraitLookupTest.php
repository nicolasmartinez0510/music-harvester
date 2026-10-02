<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Infrastructure\Providers\Deezer\DeezerApiClient;
use App\Infrastructure\Providers\Deezer\DeezerArtistPortraitLookup;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DeezerArtistPortraitLookupTest extends TestCase
{
    public function test_prefers_the_exact_artist_name_and_picture_xl(): void
    {
        Http::fake([
            'api.deezer.com/search/artist*' => Http::response([
                'data' => [
                    ['name' => 'Someone Else', 'picture_xl' => 'https://cdn.example/other.jpg'],
                    ['name' => 'Adele', 'picture_xl' => 'https://cdn.example/adele.jpg', 'picture_medium' => 'https://cdn.example/small.jpg'],
                ],
            ]),
        ]);

        $url = (new DeezerArtistPortraitLookup(new DeezerApiClient()))->portraitUrl('adele');

        $this->assertSame('https://cdn.example/adele.jpg', $url);
    }
}
