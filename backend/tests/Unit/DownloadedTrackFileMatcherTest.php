<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\IndexDownloadedTracks\DownloadedTrackFileMatcher;
use App\Domain\Music\Models\Album;
use App\Domain\Music\Models\Artist;
use App\Domain\Music\Models\Track;
use App\Infrastructure\Storage\LocalMusicStorage;
use Tests\TestCase;

class DownloadedTrackFileMatcherTest extends TestCase
{
    public function test_matches_album_layout_filenames(): void
    {
        $matcher = new DownloadedTrackFileMatcher(new LocalMusicStorage('/music'));
        $track = new Track(
            title: 'Sham Pain',
            artist: new Artist('Five Finger Death Punch'),
            album: new Album('And Justice for None'),
            index: 1,
            id: '100',
        );

        $matched = $matcher->match(
            [$track],
            ['/music/five-finger-death-punch/and-justice-for-none/01 - sham-pain.flac'],
        );

        $this->assertCount(1, $matched);
        $this->assertSame('100', $matched[0]['track']->id);
        $this->assertSame('/music/five-finger-death-punch/and-justice-for-none/01 - sham-pain.flac', $matched[0]['path']);
    }

    public function test_matches_playlist_layout_filenames(): void
    {
        $matcher = new DownloadedTrackFileMatcher(new LocalMusicStorage('/music'));
        $track = new Track(title: 'Song', artist: new Artist('Artist'), index: 2, id: 'vid1');

        $matched = $matcher->match(
            [$track],
            ['/music/playlists/5-list/02 - artist - song.mp3'],
        );

        $this->assertCount(1, $matched);
        $this->assertSame('/music/playlists/5-list/02 - artist - song.mp3', $matched[0]['path']);
    }

    public function test_pairs_a_single_unmatched_track_with_a_single_file(): void
    {
        $matcher = new DownloadedTrackFileMatcher(new LocalMusicStorage('/music'));
        $track = new Track(title: 'Renamed On Disk', artist: new Artist('Artist'), index: 1, id: '9');

        $matched = $matcher->match([$track], ['/music/artist/album/custom-name.flac']);

        $this->assertCount(1, $matched);
        $this->assertSame('/music/artist/album/custom-name.flac', $matched[0]['path']);
    }

    public function test_does_not_guess_when_several_files_are_unmatched(): void
    {
        $matcher = new DownloadedTrackFileMatcher(new LocalMusicStorage('/music'));
        $track = new Track(title: 'Missing Name', artist: new Artist('Artist'), index: 1, id: '9');

        $matched = $matcher->match(
            [$track],
            ['/music/a/01 - other.flac', '/music/a/02 - else.flac'],
        );

        $this->assertSame([], $matched);
    }
}
