<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\PlaylistCover\PlaylistArtistRanker;
use PHPUnit\Framework\TestCase;

class PlaylistArtistRankerTest extends TestCase
{
    public function test_single_artist_is_clear(): void
    {
        $rank = (new PlaylistArtistRanker())->rank(['Adele', 'adele']);

        $this->assertSame('Adele', $rank['clear']);
        $this->assertSame(['Adele'], $rank['top']);
    }

    public function test_three_tracks_ahead_of_the_next_artist_is_clear(): void
    {
        $rank = (new PlaylistArtistRanker())->rank(['A', 'A', 'A', 'B', 'B']);

        $this->assertSame('A', $rank['clear']);
    }

    public function test_tie_is_not_clear_and_keeps_the_top_four(): void
    {
        $rank = (new PlaylistArtistRanker())->rank(['A', 'A', 'B', 'B', 'C', 'D', 'E']);

        $this->assertNull($rank['clear']);
        $this->assertSame(['A', 'B', 'C', 'D'], $rank['top']);
    }

    public function test_ignores_blank_and_unknown_artist(): void
    {
        $rank = (new PlaylistArtistRanker())->rank(['Unknown Artist', '', null, '  Ada  ']);

        $this->assertSame('Ada', $rank['clear']);
    }
}
