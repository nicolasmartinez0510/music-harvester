<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_playlists', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->text('url');
            $table->string('title')->nullable();
            $table->boolean('sync_enabled')->default(true);
            $table->unsignedInteger('sync_interval_hours')->default(24);
            $table->string('default_format')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_sync_status')->default('idle');
            $table->text('last_sync_error')->nullable();
            $table->timestamps();

            $table->index('provider');
            $table->index('sync_enabled');
            $table->index('last_sync_status');
        });

        Schema::create('saved_playlist_tracks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('saved_playlist_id')
                ->constrained('saved_playlists')
                ->cascadeOnDelete();
            $table->string('external_id');
            $table->string('title');
            $table->string('artist')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->string('status')->default('pending');
            $table->text('file_path')->nullable();
            $table->unsignedBigInteger('download_job_id')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('downloaded_at')->nullable();
            $table->timestamps();

            $table->unique(['saved_playlist_id', 'external_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_playlist_tracks');
        Schema::dropIfExists('saved_playlists');
    }
};
