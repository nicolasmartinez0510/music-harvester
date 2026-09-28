<?php

declare(strict_types=1);

use App\Http\Controllers\DownloadController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\PlaylistController;
use App\Http\Controllers\ProvidersController;
use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

Route::get('/providers', ProvidersController::class);

Route::get('/downloads', [DownloadController::class, 'index']);
Route::post('/downloads', [DownloadController::class, 'store']);
Route::get('/downloads/{id}', [DownloadController::class, 'show'])->whereNumber('id');
Route::post('/downloads/{id}/retry', [DownloadController::class, 'retry'])->whereNumber('id');

Route::get('/playlists', [PlaylistController::class, 'index']);
Route::post('/playlists', [PlaylistController::class, 'store']);
Route::get('/playlists/{id}', [PlaylistController::class, 'show'])->whereNumber('id');
Route::put('/playlists/{id}', [PlaylistController::class, 'update'])->whereNumber('id');
Route::delete('/playlists/{id}', [PlaylistController::class, 'destroy'])->whereNumber('id');
Route::post('/playlists/{id}/sync', [PlaylistController::class, 'sync'])->whereNumber('id');

Route::get('/settings', [SettingsController::class, 'show']);
Route::put('/settings', [SettingsController::class, 'update']);
