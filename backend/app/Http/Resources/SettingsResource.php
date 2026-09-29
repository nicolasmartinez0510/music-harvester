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
            'is_admin' => (bool) ($settings['is_admin'] ?? false),
            'download_destination' => (string) ($settings['download_destination'] ?? 'direct'),
            'server_storage_status' => (string) ($settings['server_storage_status'] ?? 'none'),
            'effective_download_destination' => (string) ($settings['effective_download_destination'] ?? 'direct'),
            'library_root' => (string) ($settings['library_root'] ?? $settings['music_path']),
            'email_verification_enabled' => (bool) ($settings['email_verification_enabled'] ?? true),
            'metadata_enrich_enabled' => (bool) ($settings['metadata_enrich_enabled'] ?? true),
            'metadata_embed_cover' => (bool) ($settings['metadata_embed_cover'] ?? true),
            'metadata_embed_lyrics' => (bool) ($settings['metadata_embed_lyrics'] ?? true),
        ];
    }
}
