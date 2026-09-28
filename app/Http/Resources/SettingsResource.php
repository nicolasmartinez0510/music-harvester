<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin array<string, mixed>
 */
final class SettingsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $settings = is_array($this->resource) ? $this->resource : (array) $this->resource;

        return [
            'music_path' => (string) $settings['music_path'],
            'default_format' => (string) $settings['default_format'],
            'max_concurrency' => (int) $settings['max_concurrency'],
            'enabled_providers' => (string) ($settings['enabled_providers'] ?? 'youtube_music,deezer'),
            'provider_youtube_music_cookies_path' => $settings['provider_youtube_music_cookies_path'] ?? null,
            'provider_youtube_music_cookies_configured' => (bool) ($settings['provider_youtube_music_cookies_configured'] ?? false),
            'provider_deezer_arl_configured' => (bool) ($settings['provider_deezer_arl_configured'] ?? false),
            'provider_deezer_mode' => (string) ($settings['provider_deezer_mode'] ?? 'native'),
            'cookies_path' => $settings['cookies_path'] ?? null,
            'cookies_configured' => (bool) ($settings['cookies_configured'] ?? false),
        ];
    }
}
