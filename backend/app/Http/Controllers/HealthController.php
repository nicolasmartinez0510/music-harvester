<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Infrastructure\Downloader\YtDlpDownloader;
use Illuminate\Http\JsonResponse;

final class HealthController extends Controller
{
    public function __invoke(YtDlpDownloader $downloader): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'app' => config('app.name'),
            'yt_dlp' => $downloader->isAvailable(),
        ]);
    }
}
