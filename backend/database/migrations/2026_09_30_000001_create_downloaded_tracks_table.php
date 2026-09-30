<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('downloaded_tracks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('external_id');
            $table->text('file_path');
            $table->unsignedBigInteger('download_job_id')->nullable();
            $table->unsignedBigInteger('saved_playlist_track_id')->nullable();
            $table->string('title')->nullable();
            $table->string('artist')->nullable();
            $table->timestamps();

            $table->index('download_job_id');
            $table->index('saved_playlist_track_id');
            $table->index('file_path');
        });

        // NULL user_id does not collide in a composite UNIQUE, so split ownership.
        DB::statement('CREATE UNIQUE INDEX downloaded_tracks_owner_unique ON downloaded_tracks (user_id, provider, external_id) WHERE user_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX downloaded_tracks_legacy_unique ON downloaded_tracks (provider, external_id) WHERE user_id IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('downloaded_tracks');
    }
};
