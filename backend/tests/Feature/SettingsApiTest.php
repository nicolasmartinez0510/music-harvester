<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SettingsApiTest extends TestCase
{
    use RefreshDatabase;

    protected bool $authenticate = true;

    public function test_show_settings_returns_config_defaults(): void
    {
        $response = $this->getJson('/api/settings');

        $response
            ->assertOk()
            ->assertJsonPath('data.music_path', config('music.path'))
            ->assertJsonPath('data.default_format', config('music.default_format'))
            ->assertJsonPath('data.max_concurrency', config('music.max_concurrency'))
            ->assertJsonPath('data.provider_deezer_mode', 'native')
            ->assertJsonPath('data.metadata_enrich_enabled', true)
            ->assertJsonPath('data.metadata_embed_cover', true)
            ->assertJsonPath('data.metadata_embed_lyrics', true)
            ->assertJsonStructure([
                'data' => [
                    'enabled_providers',
                    'provider_youtube_music_cookies_configured',
                    'provider_deezer_arl_configured',
                    'cookies_configured',
                ],
            ]);
    }

    public function test_update_settings_persists_values(): void
    {
        $response = $this->putJson('/api/settings', [
            'music_path' => '/volume1/music',
            'default_format' => 'm4a',
            'max_concurrency' => 2,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.music_path', '/volume1/music')
            ->assertJsonPath('data.default_format', 'm4a')
            ->assertJsonPath('data.max_concurrency', 2);

        $this->assertDatabaseHas('settings', [
            'key' => 'music_path',
            'value' => '/volume1/music',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'default_format',
            'value' => 'm4a',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'max_concurrency',
            'value' => '2',
        ]);
    }

    public function test_show_settings_prefers_database_over_config(): void
    {
        DB::table('settings')->insert([
            'key' => 'music_path',
            'value' => '/custom/music',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson('/api/settings');

        $response->assertOk()->assertJsonPath('data.music_path', '/custom/music');
    }

    public function test_update_settings_validates_format(): void
    {
        $response = $this->putJson('/api/settings', [
            'default_format' => 'wav',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['default_format']);
    }

    public function test_show_settings_reports_cookies_configured_when_file_exists(): void
    {
        $cookiesFile = storage_path('framework/testing-cookies.txt');
        file_put_contents($cookiesFile, '# Netscape HTTP Cookie File');

        DB::table('settings')->insert([
            'key' => 'provider_youtube_music_cookies_path',
            'value' => $cookiesFile,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $response = $this->getJson('/api/settings');

            $response
                ->assertOk()
                ->assertJsonPath('data.provider_youtube_music_cookies_path', $cookiesFile)
                ->assertJsonPath('data.provider_youtube_music_cookies_configured', true)
                ->assertJsonPath('data.cookies_path', $cookiesFile)
                ->assertJsonPath('data.cookies_configured', true);
        } finally {
            @unlink($cookiesFile);
        }
    }

    public function test_update_settings_accepts_deezer_arl_and_mode(): void
    {
        $response = $this->putJson('/api/settings', [
            'provider_deezer_arl' => str_repeat('a', 64),
            'provider_deezer_mode' => 'hybrid',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.provider_deezer_arl_configured', true)
            ->assertJsonPath('data.provider_deezer_mode', 'hybrid');

        $this->assertDatabaseHas('settings', [
            'key' => 'provider_deezer_arl',
            'value' => str_repeat('a', 64),
        ]);
    }

    public function test_update_settings_persists_metadata_flags(): void
    {
        $response = $this->putJson('/api/settings', [
            'metadata_enrich_enabled' => false,
            'metadata_embed_cover' => true,
            'metadata_embed_lyrics' => false,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.metadata_enrich_enabled', false)
            ->assertJsonPath('data.metadata_embed_cover', true)
            ->assertJsonPath('data.metadata_embed_lyrics', false);

        $this->assertDatabaseHas('settings', [
            'key' => 'metadata_enrich_enabled',
            'value' => '0',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'metadata_embed_lyrics',
            'value' => '0',
        ]);
    }
}
