<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Music\Models\Track;
use App\Domain\Music\ValueObjects\CatalogType;
use App\Infrastructure\Providers\Deezer\DeezerProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DeezerProviderResolveTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolve_track_via_public_api(): void
    {
        Http::fake([
            'api.deezer.com/track/3135556' => Http::response([
                'id' => 3135556,
                'title' => 'Harder Better Faster Stronger',
                'duration' => 224,
                'track_position' => 4,
                'artist' => ['id' => 27, 'name' => 'Daft Punk'],
                'album' => ['id' => 302127, 'title' => 'Discovery', 'release_date' => '1999-01-01'],
                'release_date' => '2001-03-12',
                'type' => 'track',
            ]),
        ]);

        $provider = app(DeezerProvider::class);
        $resolved = $provider->resolve('https://www.deezer.com/track/3135556');

        $this->assertSame('deezer', $resolved->provider);
        $this->assertCount(1, $resolved->items);
        $item = $resolved->items[0]->item;
        $this->assertInstanceOf(Track::class, $item);
        $this->assertSame('Harder Better Faster Stronger', $item->title);
        $this->assertSame('3135556', $item->id);
        $this->assertSame(2001, $item->releaseYear);
    }

    public function test_resolve_track_uses_album_release_date_when_the_track_has_none(): void
    {
        Http::fake([
            'api.deezer.com/track/1' => Http::response([
                'id' => 1,
                'title' => 'Song',
                'duration' => 180,
                'artist' => ['name' => 'Artist'],
                'album' => ['title' => 'Deluxe', 'release_date' => '2011-06-01'],
                'type' => 'track',
            ]),
        ]);

        $resolved = app(DeezerProvider::class)->resolve('https://www.deezer.com/track/1');
        $item = $resolved->items[0]->item;

        $this->assertInstanceOf(Track::class, $item);
        $this->assertSame(2011, $item->releaseYear);
    }

    public function test_catalog_search_tracks(): void
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
                    ],
                ],
                'total' => 1,
            ]),
        ]);

        $provider = app(DeezerProvider::class);
        $hits = $provider->search('daft punk', CatalogType::Track, 10);

        $this->assertCount(1, $hits);
        $this->assertSame('3135556', $hits[0]->id);
        $this->assertSame('https://www.deezer.com/track/3135556', $hits[0]->canonicalUrl);
    }
}
