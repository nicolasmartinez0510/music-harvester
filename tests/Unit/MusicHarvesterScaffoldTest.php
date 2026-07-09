<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Music\Contracts\MusicProvider;
use App\Domain\Music\ValueObjects\AudioFormat;
use App\Domain\Music\ValueObjects\DownloadStatus;
use App\Infrastructure\Providers\MusicProviderRegistry;
use App\Infrastructure\Providers\YoutubeMusic\YoutubeMusicProvider;
use Tests\TestCase;

class MusicHarvesterScaffoldTest extends TestCase
{
    public function test_youtube_music_provider_supports_youtube_urls(): void
    {
        $provider = app(YoutubeMusicProvider::class);

        $this->assertTrue($provider->supports('https://music.youtube.com/watch?v=abc'));
        $this->assertTrue($provider->supports('https://www.youtube.com/watch?v=abc'));
        $this->assertFalse($provider->supports('https://open.spotify.com/track/abc'));
    }

    public function test_provider_registry_resolves_youtube_urls(): void
    {
        $registry = app(MusicProviderRegistry::class);
        $provider = $registry->resolveForUrl('https://music.youtube.com/playlist?list=abc');

        $this->assertInstanceOf(MusicProvider::class, $provider);
        $this->assertSame('youtube_music', $provider->name());
    }

    public function test_audio_format_extensions(): void
    {
        $this->assertSame('mp3', AudioFormat::Mp3_320->extension());
        $this->assertSame('m4a', AudioFormat::M4a->extension());
    }

    public function test_download_status_terminal_states(): void
    {
        $this->assertTrue(DownloadStatus::Done->isTerminal());
        $this->assertTrue(DownloadStatus::Failed->isTerminal());
        $this->assertFalse(DownloadStatus::Pending->isTerminal());
    }
}
