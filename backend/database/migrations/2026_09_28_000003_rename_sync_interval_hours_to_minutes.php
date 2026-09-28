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
        if (! Schema::hasTable('saved_playlists')) {
            return;
        }

        if (Schema::hasColumn('saved_playlists', 'sync_interval_hours')
            && ! Schema::hasColumn('saved_playlists', 'sync_interval_minutes')) {
            Schema::table('saved_playlists', function (Blueprint $table) {
                $table->unsignedInteger('sync_interval_minutes')->default(5);
            });

            $rows = DB::table('saved_playlists')->select('id', 'sync_interval_hours')->get();
            foreach ($rows as $row) {
                $hours = max(1, (int) $row->sync_interval_hours);
                DB::table('saved_playlists')->where('id', $row->id)->update([
                    'sync_interval_minutes' => $hours * 60,
                ]);
            }

            Schema::table('saved_playlists', function (Blueprint $table) {
                $table->dropColumn('sync_interval_hours');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('saved_playlists')) {
            return;
        }

        if (Schema::hasColumn('saved_playlists', 'sync_interval_minutes')
            && ! Schema::hasColumn('saved_playlists', 'sync_interval_hours')) {
            Schema::table('saved_playlists', function (Blueprint $table) {
                $table->unsignedInteger('sync_interval_hours')->default(24);
            });

            $rows = DB::table('saved_playlists')->select('id', 'sync_interval_minutes')->get();
            foreach ($rows as $row) {
                $minutes = max(1, (int) $row->sync_interval_minutes);
                DB::table('saved_playlists')->where('id', $row->id)->update([
                    'sync_interval_hours' => max(1, (int) ceil($minutes / 60)),
                ]);
            }

            Schema::table('saved_playlists', function (Blueprint $table) {
                $table->dropColumn('sync_interval_minutes');
            });
        }
    }
};
