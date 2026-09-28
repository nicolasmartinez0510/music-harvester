<?php

declare(strict_types=1);

use App\Http\Controllers\CatalogController;
use App\Http\Controllers\DownloadController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\LibraryController;
use App\Http\Controllers\PlaylistController;
use App\Http\Controllers\ProvidersController;
use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

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
Route::get('/downloads/{id}', [DownloadController::class, 'show'])->whereNumber('id');
Route::delete('/downloads/{id}', [DownloadController::class, 'destroy'])->whereNumber('id');
Route::post('/downloads/{id}/retry', [DownloadController::class, 'retry'])->whereNumber('id');

Route::get('/playlists', [PlaylistController::class, 'index']);
Route::post('/playlists', [PlaylistController::class, 'store']);
Route::get('/playlists/{id}', [PlaylistController::class, 'show'])->whereNumber('id');
Route::put('/playlists/{id}', [PlaylistController::class, 'update'])->whereNumber('id');
Route::delete('/playlists/{id}', [PlaylistController::class, 'destroy'])->whereNumber('id');
Route::post('/playlists/{id}/sync', [PlaylistController::class, 'sync'])->whereNumber('id');

Route::get('/settings', [SettingsController::class, 'show']);
Route::put('/settings', [SettingsController::class, 'update']);
