<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saved_playlists', function (Blueprint $table) {
            $table->string('cover_mode')->default('auto')->after('default_format');
        });
    }

    public function down(): void
    {
        Schema::table('saved_playlists', function (Blueprint $table) {
            $table->dropColumn('cover_mode');
        });
    }
};
