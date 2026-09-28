<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Music\Models\Artist;
use App\Domain\Music\Models\Track;
use App\Infrastructure\Storage\LocalMusicStorage;
use Tests\TestCase;

class LocalMusicStoragePlaylistTest extends TestCase
{
    public function test_playlist_directory_and_track_filename(): void
    {
        $storage = new LocalMusicStorage('/music');

        $this->assertSame('/music/playlists/3-my-mix', $storage->playlistDirectory(3, 'My Mix'));
        $this->assertSame('3-my-mix', $storage->playlistFolderName(3, 'My Mix'));
        $this->assertSame('/music/playlists/9-playlist', $storage->playlistDirectory(9, null));
        $this->assertSame([], $storage->playlistDirectoriesForId(3));

        $track = new Track(
            title: 'Hello World',
            artist: new Artist('Daft Punk'),
            index: 2,
        );

        $this->assertSame(
            '02 - daft-punk - hello-world.flac',
            $storage->playlistTrackFilename($track, 'flac'),
        );
    }
}
