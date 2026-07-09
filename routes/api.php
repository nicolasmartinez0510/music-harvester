<?php

use App\Http\Controllers\DownloadController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

Route::get('/downloads', [DownloadController::class, 'index']);
Route::post('/downloads', [DownloadController::class, 'store']);
Route::get('/downloads/{id}', [DownloadController::class, 'show'])->whereNumber('id');
Route::post('/downloads/{id}/retry', [DownloadController::class, 'retry'])->whereNumber('id');

Route::get('/settings', [SettingsController::class, 'show']);
Route::put('/settings', [SettingsController::class, 'update']);
