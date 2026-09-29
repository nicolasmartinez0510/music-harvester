<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\Settings\ProviderSettingsResolver;
use App\Domain\Music\Contracts\SettingsRepository;
use App\Infrastructure\Storage\DownloadedFilesCleanup;
use Mockery;
use Tests\TestCase;

class DownloadedFilesCleanupTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_files_present_when_path_matches_config_music_root(): void
    {
        $musicPath = storage_path('framework/testing/music-'.uniqid());
        mkdir($musicPath.'/artist/album', 0777, true);
        $file = $musicPath.'/artist/album/01 - song.mp3';
        file_put_contents($file, 'x');
        config(['music.path' => $musicPath]);

        $cleanup = $this->cleanupWithStoredPath('/volume2/music');

        $this->assertTrue($cleanup->filesPresent([
            'destination_path' => '/volume2/music/artist/album/01 - song.mp3',
            'downloaded_paths' => json_encode(['/volume2/music/artist/album/01 - song.mp3']),
        ]));

        @unlink($file);
        @rmdir($musicPath.'/artist/album');
        @rmdir($musicPath.'/artist');
        @rmdir($musicPath);
    }

    public function test_delete_job_files_remaps_host_path_to_container_mount(): void
    {
        $musicPath = storage_path('framework/testing/music-'.uniqid());
        mkdir($musicPath.'/artist/album', 0777, true);
        $file = $musicPath.'/artist/album/01 - song.mp3';
        file_put_contents($file, 'x');
        config(['music.path' => $musicPath]);

        $cleanup = $this->cleanupWithStoredPath('/volume2/music');
        $cleanup->deleteJobFiles([
            'destination_path' => '/volume2/music/artist/album/01 - song.mp3',
            'downloaded_paths' => json_encode(['/volume2/music/artist/album/01 - song.mp3']),
        ]);

        $this->assertFileDoesNotExist($file);

        @rmdir($musicPath.'/artist/album');
        @rmdir($musicPath.'/artist');
        @rmdir($musicPath);
    }

    public function test_decode_paths_handles_double_encoded_json(): void
    {
        $musicPath = storage_path('framework/testing/music-'.uniqid());
        mkdir($musicPath, 0777, true);
        $file = $musicPath.'/track.mp3';
        file_put_contents($file, 'x');
        config(['music.path' => $musicPath]);

        $cleanup = $this->cleanupWithStoredPath($musicPath);
        $doubleEncoded = json_encode(json_encode([$file]));

        $this->assertTrue($cleanup->filesPresent([
            'destination_path' => null,
            'downloaded_paths' => $doubleEncoded,
        ]));

        @unlink($file);
        @rmdir($musicPath);
    }

    private function cleanupWithStoredPath(string $storedMusicPath): DownloadedFilesCleanup
    {
        $repo = Mockery::mock(SettingsRepository::class);
        $repo->shouldReceive('get')
            ->with('music_path')
            ->andReturn($storedMusicPath);
        $repo->shouldReceive('get')->andReturn(null);

        return new DownloadedFilesCleanup(new ProviderSettingsResolver($repo));
    }
}
