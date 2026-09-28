<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProvidersApiTest extends TestCase
{
    use RefreshDatabase;

    protected bool $authenticate = true;

    public function test_list_providers_returns_youtube_and_deezer(): void
    {
        $response = $this->getJson('/api/providers');

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertSame(['youtube_music', 'deezer'], $names);

        $deezer = collect($response->json('data'))->firstWhere('name', 'deezer');
        $this->assertTrue($deezer['has_catalog']);
        $this->assertFalse($deezer['has_library']);
        $this->assertContains('flac', $deezer['qualities']);
        $this->assertSame('native', $deezer['mode']);

        $youtube = collect($response->json('data'))->firstWhere('name', 'youtube_music');
        $this->assertFalse($youtube['has_library']);
    }

    public function test_list_providers_has_library_when_arl_configured(): void
    {
        DB::table('settings')->insert([
            'key' => 'provider_deezer_arl',
            'value' => str_repeat('a', 64),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson('/api/providers');
        $deezer = collect($response->json('data'))->firstWhere('name', 'deezer');
        $this->assertTrue($deezer['has_library']);
    }

    public function test_list_providers_respects_enabled_providers_setting(): void
    {
        DB::table('settings')->insert([
            'key' => 'enabled_providers',
            'value' => 'youtube_music',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson('/api/providers');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'youtube_music');
    }
}
