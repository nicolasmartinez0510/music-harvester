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
    'streamrip_bin' => env('STREAMRIP_BIN', 'rip'),
];
