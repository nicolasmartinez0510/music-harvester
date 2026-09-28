<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name')->default('');
            $table->string('last_name')->default('');
            $table->string('username')->nullable()->unique();
            $table->string('avatar_id')->default('meme-01');
            $table->string('role')->default('user');
            $table->string('server_storage_status')->default('none');
            $table->string('download_destination')->default('direct');
        });

        Schema::create('email_verification_codes', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('code_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamps();
        });

        Schema::create('password_reset_codes', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('code_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamps();
        });

        Schema::create('user_provider_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->text('arl')->nullable();
            $table->string('cookies_path')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'provider']);
        });

        Schema::create('artist_favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('external_id');
            $table->string('name');
            $table->text('image_url')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'provider', 'external_id']);
        });

        Schema::table('download_jobs', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->string('download_destination')->default('server');
        });

        Schema::table('saved_playlists', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        $adminId = $this->ensureAdmin();

        DB::table('download_jobs')->whereNull('user_id')->update(['user_id' => $adminId]);
        DB::table('saved_playlists')->whereNull('user_id')->update(['user_id' => $adminId]);
        $this->copyGlobalCredentials($adminId);
    }

    public function down(): void
    {
        Schema::table('saved_playlists', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });

        Schema::table('download_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn('download_destination');
        });

        Schema::dropIfExists('artist_favorites');
        Schema::dropIfExists('user_provider_credentials');
        Schema::dropIfExists('password_reset_codes');
        Schema::dropIfExists('email_verification_codes');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'first_name',
                'last_name',
                'username',
                'avatar_id',
                'role',
                'server_storage_status',
                'download_destination',
            ]);
        });
    }

    private function ensureAdmin(): int
    {
        $email = (string) env('ADMIN_EMAIL', 'admin@music-harvester.local');
        $existing = DB::table('users')->where('email', $email)->value('id');

        if ($existing !== null) {
            DB::table('users')->where('id', $existing)->update([
                'role' => 'admin',
                'server_storage_status' => 'approved',
                'download_destination' => 'server',
                'email_verified_at' => now(),
            ]);

            return (int) $existing;
        }

        $first = (string) env('ADMIN_FIRST_NAME', 'Admin');
        $last = (string) env('ADMIN_LAST_NAME', 'Harvester');

        return (int) DB::table('users')->insertGetId([
            'name' => trim($first.' '.$last),
            'first_name' => $first,
            'last_name' => $last,
            'username' => (string) env('ADMIN_USERNAME', 'admin'),
            'email' => $email,
            'email_verified_at' => now(),
            'password' => Hash::make((string) env('ADMIN_PASSWORD', 'admin')),
            'avatar_id' => 'meme-01',
            'role' => 'admin',
            'server_storage_status' => 'approved',
            'download_destination' => 'server',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function copyGlobalCredentials(int $adminId): void
    {
        $arl = DB::table('settings')->where('key', 'provider_deezer_arl')->value('value');
        if (is_string($arl) && $arl !== '') {
            DB::table('user_provider_credentials')->updateOrInsert(
                ['user_id' => $adminId, 'provider' => 'deezer'],
                ['arl' => $arl, 'created_at' => now(), 'updated_at' => now()],
            );
        }

        $cookies = DB::table('settings')->where('key', 'provider_youtube_music_cookies_path')->value('value')
            ?? DB::table('settings')->where('key', 'cookies_path')->value('value');

        if (is_string($cookies) && $cookies !== '') {
            DB::table('user_provider_credentials')->updateOrInsert(
                ['user_id' => $adminId, 'provider' => 'youtube_music'],
                ['cookies_path' => $cookies, 'created_at' => now(), 'updated_at' => now()],
            );
        }
    }
};
