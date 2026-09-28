<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DeezerDownloadApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_download_accepts_deezer_track_url(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/downloads', [
            'url' => 'https://www.deezer.com/track/3135556',
            'format' => 'flac',
        ]);

        $response
            ->assertAccepted()
            ->assertJsonPath('data.provider', 'deezer')
            ->assertJsonPath('data.kind', 'track')
            ->assertJsonPath('data.format', 'flac');
    }

    public function test_create_download_detects_deezer_album_and_playlist_kinds(): void
    {
        Queue::fake();

        $this->postJson('/api/downloads', [
            'url' => 'https://www.deezer.com/album/302127',
        ])->assertAccepted()->assertJsonPath('data.kind', 'album');

        $this->postJson('/api/downloads', [
            'url' => 'https://www.deezer.com/playlist/908622995',
        ])->assertAccepted()->assertJsonPath('data.kind', 'playlist');
    }

    public function test_create_download_provider_override(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/downloads', [
            'url' => 'https://www.deezer.com/track/3135556',
            'provider' => 'deezer',
            'format' => 'flac',
        ]);

        $response
            ->assertAccepted()
            ->assertJsonPath('data.provider', 'deezer');
    }
}
