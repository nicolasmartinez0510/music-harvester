<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Music\ValueObjects\AudioFormat;
use App\Infrastructure\Providers\Deezer\StreamripDeezerDownloader;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class StreamripDeezerDownloaderTest extends TestCase
{
    public function test_playlist_downloads_do_not_save_a_cover_jpg(): void
    {
        $method = new ReflectionMethod(StreamripDeezerDownloader::class, 'buildConfig');
        $config = $method->invoke(
            new StreamripDeezerDownloader(),
            'arl-token',
            AudioFormat::Flac,
            '/music/playlists/1-rap',
            false,
        );

        $this->assertIsString($config);
        $this->assertStringContainsString('save_artwork = false', $config);
        $this->assertStringContainsString('embed = true', $config);
    }

    public function test_album_and_track_downloads_save_a_cover_jpg(): void
    {
        $method = new ReflectionMethod(StreamripDeezerDownloader::class, 'buildConfig');
        $config = $method->invoke(
            new StreamripDeezerDownloader(),
            'arl-token',
            AudioFormat::Flac,
            '/music/eminem/curtain-call',
        );

        $this->assertIsString($config);
        $this->assertStringContainsString('save_artwork = true', $config);
    }
}
