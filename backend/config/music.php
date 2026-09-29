<?php

declare(strict_types=1);

return [
    'path' => env('MUSIC_PATH', storage_path('music')),
    'cookies_path' => env('COOKIES_PATH'),
    'youtube_music_cookies_path' => env('YOUTUBE_MUSIC_COOKIES_PATH', env('COOKIES_PATH')),
    'deezer_arl' => env('DEEZER_ARL'),
    'deezer_mode' => env('DEEZER_MODE', 'native'),
    'enabled_providers' => env('ENABLED_PROVIDERS', 'youtube_music,deezer'),
    'default_format' => env('MUSIC_DEFAULT_FORMAT', 'mp3_320'),
    'max_concurrency' => (int) env('MUSIC_MAX_CONCURRENCY', 1),
    'default_sync_interval_minutes' => (int) env('MUSIC_DEFAULT_SYNC_INTERVAL_MINUTES', 5),
    'streamrip_bin' => env('STREAMRIP_BIN', 'rip'),
    'metadata_enrich_enabled' => filter_var(env('METADATA_ENRICH_ENABLED', 'true'), FILTER_VALIDATE_BOOLEAN),
    'metadata_embed_cover' => filter_var(env('METADATA_EMBED_COVER', 'true'), FILTER_VALIDATE_BOOLEAN),
    'metadata_embed_lyrics' => filter_var(env('METADATA_EMBED_LYRICS', 'true'), FILTER_VALIDATE_BOOLEAN),
    'metadata_python' => env('METADATA_PYTHON', 'python3'),
    'metadata_script_path' => env('METADATA_SCRIPT_PATH', (static function (): string {
        $installed = '/usr/local/bin/apply-audio-metadata.py';
        if (is_file($installed)) {
            return $installed;
        }

        return dirname(__DIR__, 2).'/docker/scripts/apply-audio-metadata.py';
    })()),
];
