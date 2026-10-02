<?php

declare(strict_types=1);

use App\Http\Controllers\AccountSettingsController;
use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\DownloadController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\LibraryController;
use App\Http\Controllers\PlaylistController;
use App\Http\Controllers\ProvidersController;
use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

Route::get('/auth/avatars', [AuthController::class, 'avatars']);
Route::get('/auth/options', [AuthController::class, 'options']);
Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:8,1');
Route::post('/auth/verify-email', [AuthController::class, 'verifyEmail'])->middleware('throttle:8,1');
Route::post('/auth/resend-verification', [AuthController::class, 'resendVerification'])->middleware('throttle:4,1');
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:30,1');
Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:4,1');
Route::get('/auth/password-reset/validate', [AuthController::class, 'validatePasswordReset'])->middleware('throttle:12,1');
Route::post('/auth/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:8,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::put('/me/preferences', [AccountSettingsController::class, 'updatePreferences']);
    Route::put('/settings/providers/deezer', [AccountSettingsController::class, 'updateDeezerArl']);
    Route::post('/settings/providers/youtube-music/cookies', [AccountSettingsController::class, 'uploadYoutubeCookies']);
    Route::get('/favorites/artists', [AccountSettingsController::class, 'listFavorites']);
    Route::post('/favorites/artists/toggle', [AccountSettingsController::class, 'toggleFavorite']);

    Route::get('/providers', ProvidersController::class);

    Route::get('/catalog/search', [CatalogController::class, 'search']);
    Route::get('/catalog/{provider}/artists/{id}', [CatalogController::class, 'artist']);
    Route::get('/catalog/{provider}/albums/{id}', [CatalogController::class, 'album']);
    Route::get('/catalog/{provider}/playlists/{id}', [CatalogController::class, 'playlist']);

    Route::get('/library/{provider}/{kind}', LibraryController::class)
        ->whereIn('kind', ['artists', 'albums', 'tracks', 'playlists']);

    Route::get('/downloads', [DownloadController::class, 'index']);
    Route::post('/downloads', [DownloadController::class, 'store']);
    Route::delete('/downloads', [DownloadController::class, 'destroyAll']);
    Route::get('/downloads/{id}/artifact', [DownloadController::class, 'artifact'])->whereNumber('id');
    Route::get('/downloads/{id}', [DownloadController::class, 'show'])->whereNumber('id');
    Route::delete('/downloads/{id}', [DownloadController::class, 'destroy'])->whereNumber('id');
    Route::post('/downloads/{id}/retry', [DownloadController::class, 'retry'])->whereNumber('id');

    Route::get('/playlists', [PlaylistController::class, 'index']);
    Route::post('/playlists', [PlaylistController::class, 'store']);
    Route::get('/playlists/{id}', [PlaylistController::class, 'show'])->whereNumber('id');
    Route::get('/playlists/{id}/cover', [PlaylistController::class, 'showCover'])->whereNumber('id');
    Route::post('/playlists/{id}/cover', [PlaylistController::class, 'storeCover'])->whereNumber('id');
    Route::put('/playlists/{id}', [PlaylistController::class, 'update'])->whereNumber('id');
    Route::delete('/playlists/{id}', [PlaylistController::class, 'destroy'])->whereNumber('id');
    Route::post('/playlists/{id}/sync', [PlaylistController::class, 'sync'])->whereNumber('id');

    Route::get('/settings', [SettingsController::class, 'show']);

    Route::middleware('admin')->group(function () {
        Route::put('/settings', [SettingsController::class, 'update']);
        Route::get('/admin/users', [AdminUserController::class, 'index']);
        Route::delete('/admin/users/{id}', [AdminUserController::class, 'destroy'])->whereNumber('id');
        Route::post('/admin/users/{id}/approve-server-storage', [AdminUserController::class, 'approveServerStorage'])->whereNumber('id');
    });
});
