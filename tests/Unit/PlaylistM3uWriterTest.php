<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Infrastructure\Storage\LocalMusicStorage;
use App\Infrastructure\Storage\PlaylistM3uWriter;
use Tests\TestCase;

class PlaylistM3uWriterTest extends TestCase
{
    public function test_writes_extended_m3u_with_relative_paths(): void
    {
        $base = storage_path('framework/testing/m3u-'.uniqid());
        $storage = new LocalMusicStorage($base);
        $writer = new PlaylistM3uWriter($storage);

        $dir = $storage->playlistDirectory(1, 'Chill');
        $storage->ensureDirectory($dir);
        $audioA = $dir.'/01 - artist-a - song-a.mp3';
        $audioB = $dir.'/02 - artist-b - song-b.mp3';
        file_put_contents($audioA, 'a');
        file_put_contents($audioB, 'b');

        $path = $writer->write(
            ['id' => 1, 'title' => 'Chill'],
            [
                [
                    'title' => 'Song A',
                    'artist' => 'Artist A',
                    'status' => 'downloaded',
                    'file_path' => $audioA,
                    'position' => 1,
                ],
                [
                    'title' => 'Pending',
                    'artist' => 'X',
                    'status' => 'pending',
                    'file_path' => null,
                    'position' => 2,
                ],
                [
                    'title' => 'Song B',
                    'artist' => 'Artist B',
                    'status' => 'downloaded',
                    'file_path' => $audioB,
                    'position' => 3,
                ],
            ],
        );

        $this->assertSame($dir.'/1-chill.m3u', $path);
        $contents = (string) file_get_contents($path);

        $this->assertStringContainsString('#EXTM3U', $contents);
        $this->assertStringContainsString('#PLAYLIST:Chill', $contents);
        $this->assertStringContainsString('#EXTINF:-1,Artist A - Song A', $contents);
        $this->assertStringContainsString("01 - artist-a - song-a.mp3\n", $contents);
        $this->assertStringContainsString('#EXTINF:-1,Artist B - Song B', $contents);
        $this->assertStringNotContainsString('Pending', $contents);

        $this->rmTree($base);
    }

    private function rmTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.'/'.$item;
            if (is_dir($path)) {
                $this->rmTree($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
