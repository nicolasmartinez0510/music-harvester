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

    public function test_writes_library_relative_path_for_reused_album_files(): void
    {
        $base = storage_path('framework/testing/m3u-'.uniqid());
        $storage = new LocalMusicStorage($base);
        $writer = new PlaylistM3uWriter($storage);

        $playlistDir = $storage->playlistDirectory(1, 'Chill');
        $storage->ensureDirectory($playlistDir);
        $albumDir = $base.'/artist/album';
        mkdir($albumDir, 0777, true);
        $albumFile = $albumDir.'/03 - title.flac';
        file_put_contents($albumFile, 'a');

        $path = $writer->write(
            ['id' => 1, 'title' => 'Chill'],
            [
                [
                    'title' => 'Title',
                    'artist' => 'Artist',
                    'status' => 'existing',
                    'file_path' => $albumFile,
                    'position' => 1,
                ],
            ],
        );

        $contents = (string) file_get_contents($path);
        $this->assertStringContainsString("#EXTINF:-1,Artist - Title\n../../artist/album/03 - title.flac\n", $contents);

        $this->rmTree($base);
    }

    public function test_replaces_m3u_that_the_current_user_cannot_overwrite(): void
    {
        $setpriv = null;
        foreach (['/usr/bin/setpriv', '/bin/setpriv'] as $candidate) {
            if (is_executable($candidate)) {
                $setpriv = $candidate;
                break;
            }
        }
        if ($setpriv === null || ! function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            $this->markTestSkipped('Needs root and setpriv to run the writer as www-data.');
        }

        $base = storage_path('framework/testing/m3u-'.uniqid());
        $storage = new LocalMusicStorage($base);
        $dir = $storage->playlistDirectory(15, 'Rock alternativo');
        $storage->ensureDirectory($dir);
        $audio = $dir.'/68 - skillet - psycho-in-my-head.flac';
        $m3u = $dir.'/15-rock-alternativo.m3u';
        file_put_contents($audio, 'a');
        file_put_contents($m3u, "old\n");
        chmod($base, 0777);
        chmod(dirname($dir), 0777);
        chmod($dir, 0777);
        chmod($audio, 0644);
        chmod($m3u, 0644);

        $runner = $base.'/replace-m3u.php';
        $autoload = dirname(__DIR__, 2).'/vendor/autoload.php';
        file_put_contents($runner, <<<PHP
<?php
require {$this->export($autoload)};
\$storage = new App\Infrastructure\Storage\LocalMusicStorage({$this->export($base)});
\$writer = new App\Infrastructure\Storage\PlaylistM3uWriter(\$storage);
\$path = \$writer->write(
    ['id' => 15, 'title' => 'Rock alternativo'],
    [[
        'title' => 'Psycho In My Head',
        'artist' => 'Skillet',
        'status' => 'downloaded',
        'file_path' => {$this->export($audio)},
        'position' => 68,
    ]],
);
echo \$path;
PHP);
        chmod($runner, 0644);

        $process = proc_open(
            [$setpriv, '--reuid=www-data', '--regid=www-data', '--init-groups', 'php', $runner],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        try {
            $this->assertSame(0, $exit, $stderr);
            $contents = (string) file_get_contents($m3u);
            $this->assertStringContainsString('#EXTM3U', $contents);
            $this->assertStringContainsString('68 - skillet - psycho-in-my-head.flac', $contents);
            $this->assertStringNotContainsString('old', $contents);
            $this->assertSame($m3u, trim((string) $stdout));
        } finally {
            $this->rmTree($base);
        }
    }

    private function export(string $value): string
    {
        return var_export($value, true);
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
