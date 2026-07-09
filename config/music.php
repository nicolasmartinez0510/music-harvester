<?php

declare(strict_types=1);

return [
    'path' => env('MUSIC_PATH', storage_path('music')),
    'cookies_path' => env('COOKIES_PATH'),
    'default_format' => env('MUSIC_DEFAULT_FORMAT', 'mp3_320'),
    'max_concurrency' => (int) env('MUSIC_MAX_CONCURRENCY', 1),
];
