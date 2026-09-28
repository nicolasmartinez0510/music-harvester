<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Music\ValueObjects\PlaylistSyncStatus;
use App\Jobs\ProcessPlaylistSyncJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SyncPlaylistsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_syncs_due_playlists_and_skips_not_due(): void
    {
        Queue::fake();

        $dueId = (int) DB::table('saved_playlists')->insertGetId([
            'provider' => 'youtube_music',
            'url' => 'https://music.youtube.com/playlist?list=PLdue',
            'title' => 'Due',
            'sync_enabled' => true,
            'sync_interval_minutes' => 5,
            'last_synced_at' => now()->subMinutes(6),
            'last_sync_status' => PlaylistSyncStatus::Done->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('saved_playlists')->insert([
            'provider' => 'youtube_music',
            'url' => 'https://music.youtube.com/playlist?list=PLfresh',
            'title' => 'Fresh',
            'sync_enabled' => true,
            'sync_interval_minutes' => 5,
            'last_synced_at' => now()->subMinutes(1),
            'last_sync_status' => PlaylistSyncStatus::Done->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('saved_playlists')->insert([
            'provider' => 'youtube_music',
            'url' => 'https://music.youtube.com/playlist?list=PLdisabled',
            'title' => 'Disabled',
            'sync_enabled' => false,
            'sync_interval_minutes' => 5,
            'last_synced_at' => now()->subDays(2),
            'last_sync_status' => PlaylistSyncStatus::Done->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exit = Artisan::call('playlists:sync');

        $this->assertSame(0, $exit);
        Queue::assertPushed(ProcessPlaylistSyncJob::class, 1);
        Queue::assertPushed(ProcessPlaylistSyncJob::class, fn (ProcessPlaylistSyncJob $job) => $job->savedPlaylistId === $dueId);
    }

    public function test_never_synced_playlist_is_due(): void
    {
        Queue::fake();

        $id = (int) DB::table('saved_playlists')->insertGetId([
            'provider' => 'youtube_music',
            'url' => 'https://music.youtube.com/playlist?list=PLnew',
            'title' => null,
            'sync_enabled' => true,
            'sync_interval_minutes' => 5,
            'last_synced_at' => null,
            'last_sync_status' => PlaylistSyncStatus::Idle->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Artisan::call('playlists:sync');

        Queue::assertPushed(ProcessPlaylistSyncJob::class, fn (ProcessPlaylistSyncJob $job) => $job->savedPlaylistId === $id);
    }
}
