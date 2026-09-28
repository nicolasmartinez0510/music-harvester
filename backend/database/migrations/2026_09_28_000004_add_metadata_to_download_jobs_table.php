<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('download_jobs', function (Blueprint $table) {
            $table->string('title')->nullable()->after('kind');
            $table->string('artist')->nullable()->after('title');
            $table->json('downloaded_paths')->nullable()->after('destination_path');
        });
    }

    public function down(): void
    {
        Schema::table('download_jobs', function (Blueprint $table) {
            $table->dropColumn(['title', 'artist', 'downloaded_paths']);
        });
    }
};
