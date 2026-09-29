<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Music\Models\Artist;
use App\Domain\Music\Models\Track;
use App\Domain\Music\ValueObjects\LyricsPayload;
use App\Domain\Music\ValueObjects\MetadataEnrichContext;
use App\Domain\Music\ValueObjects\ResolvedKind;
use App\Infrastructure\Metadata\Deezer\DeezerMetadataMapper;
use Tests\TestCase;

class DeezerMetadataMapperTest extends TestCase
{
    public function test_maps_album_track_with_contributors_and_lyrics(): void
    {
        $track = new Track(title: 'Fallback', artist: new Artist('Fallback'), index: 1, id: '3135556');
        $mapper = new DeezerMetadataMapper;

        $metadata = $mapper->map(
            $track,
            [
                'id' => 3135556,
                'title' => 'Harder Better Faster Stronger',
                'title_version' => 'Album Version',
                'isrc' => 'GBDUW0000059',
                'track_position' => 4,
                'disk_number' => 1,
                'artist' => ['name' => 'Daft Punk'],
                'contributors' => [
                    ['name' => 'Daft Punk', 'role' => 'Main'],
                    ['name' => 'Pharrell Williams', 'role' => 'Featured'],
                    ['name' => 'Thomas Bangalter', 'role' => 'Composer'],
                    ['name' => 'Guy-Manuel de Homem-Christo', 'role' => 'Writer'],
                ],
            ],
            [
                'id' => 302127,
                'title' => 'Discovery',
                'release_date' => '2001-03-12',
                'nb_tracks' => 14,
                'artist' => ['name' => 'Daft Punk'],
                'genres' => ['data' => [['name' => 'Dance'], ['name' => 'Electronic']]],
            ],
            new LyricsPayload("Work it\nMake it", '[00:00.50]Work it'),
            'jpeg-bytes',
            'image/jpeg',
            new MetadataEnrichContext(kind: ResolvedKind::Album),
        );

        $this->assertSame('Harder Better Faster Stronger (Album Version)', $metadata->displayTitle());
        $this->assertSame('Daft Punk feat. Pharrell Williams', $metadata->artistCredit());
        $this->assertSame('Daft Punk', $metadata->albumArtist);
        $this->assertSame('Discovery', $metadata->albumTitle);
        $this->assertSame(4, $metadata->trackNumber);
        $this->assertSame(14, $metadata->trackTotal);
        $this->assertSame(1, $metadata->discNumber);
        $this->assertSame('2001-03-12', $metadata->releaseDate);
        $this->assertSame(['Dance', 'Electronic'], $metadata->genres);
        $this->assertSame(['Thomas Bangalter', 'Guy-Manuel de Homem-Christo'], $metadata->composers);
        $this->assertSame('GBDUW0000059', $metadata->isrc);
        $this->assertSame('3135556', $metadata->deezerTrackId);
        $this->assertSame('jpeg-bytes', $metadata->coverBytes);
        $this->assertSame("Work it\nMake it", $metadata->lyricsPlain);
        $this->assertSame('[00:00.50]Work it', $metadata->lyricsSynced);
    }

    public function test_playlist_uses_playlist_position_instead_of_album_track_number(): void
    {
        $track = new Track(title: 'Song', index: 2, id: '10');
        $metadata = (new DeezerMetadataMapper)->map(
            $track,
            [
                'id' => 10,
                'title' => 'Song',
                'track_position' => 9,
                'artist' => ['name' => 'Artist'],
            ],
            ['title' => 'Real Album', 'nb_tracks' => 12],
            null,
            null,
            null,
            new MetadataEnrichContext(kind: ResolvedKind::Playlist, trackTotal: 8),
        );

        $this->assertSame(2, $metadata->trackNumber);
        $this->assertSame(8, $metadata->trackTotal);
        $this->assertSame('Real Album', $metadata->albumTitle);
    }
}
