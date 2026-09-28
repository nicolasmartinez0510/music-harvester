<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProvidersApiTest extends TestCase
{
    use RefreshDatabase;

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
        $this->assertContains('flac', $deezer['qualities']);
        $this->assertSame('native', $deezer['mode']);
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
