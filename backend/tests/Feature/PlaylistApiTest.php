<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Music\ValueObjects\PlaylistSyncStatus;
use App\Domain\Music\ValueObjects\PlaylistTrackStatus;
use App\Jobs\ProcessPlaylistSyncJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PlaylistApiTest extends TestCase
{
    use RefreshDatabase;

    protected bool $authenticate = true;

    public function test_create_playlist_returns_created_and_dispatches_sync(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/playlists', [
            'url' => 'https://music.youtube.com/playlist?list=PLtest123',
            'sync_now' => true,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.provider', 'youtube_music')
            ->assertJsonPath('data.url', 'https://music.youtube.com/playlist?list=PLtest123')
            ->assertJsonPath('data.sync_enabled', true)
            ->assertJsonPath('data.last_sync_status', PlaylistSyncStatus::Running->value);

        $id = (int) $response->json('data.id');
        $this->assertDatabaseHas('saved_playlists', [
            'id' => $id,
            'provider' => 'youtube_music',
        ]);

        Queue::assertPushed(ProcessPlaylistSyncJob::class, fn (ProcessPlaylistSyncJob $job) => $job->savedPlaylistId === $id);
    }

    public function test_create_playlist_is_idempotent_for_same_url(): void
    {
        Queue::fake();

        $first = $this->postJson('/api/playlists', [
            'url' => 'https://www.deezer.com/playlist/908622995',
            'sync_now' => true,
        ]);
        $first->assertCreated();
        $id = (int) $first->json('data.id');

        $second = $this->postJson('/api/playlists', [
            'url' => 'https://www.deezer.com/playlist/908622995',
            'sync_now' => true,
        ]);
        $second
            ->assertOk()
            ->assertJsonPath('data.id', $id);

        $this->assertSame(1, DB::table('saved_playlists')->count());
    }

    public function test_create_playlist_without_sync_now_does_not_dispatch(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/playlists', [
            'url' => 'https://music.youtube.com/playlist?list=PLnosync',
            'sync_now' => false,
        ]);

        $response->assertCreated();
        Queue::assertNothingPushed();
    }

    public function test_create_playlist_rejects_unsupported_url(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/playlists', [
            'url' => 'https://open.spotify.com/playlist/abc',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('message', 'No music provider supports this URL: https://open.spotify.com/playlist/abc');

        Queue::assertNothingPushed();
    }

    public function test_list_playlists_includes_counts(): void
    {
        $playlistId = $this->insertPlaylist();

        DB::table('saved_playlist_tracks')->insert([
            [
                'saved_playlist_id' => $playlistId,
                'external_id' => 'one',
                'title' => 'Song One',
                'artist' => 'Artist',
                'position' => 1,
                'status' => PlaylistTrackStatus::Downloaded->value,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'saved_playlist_id' => $playlistId,
                'external_id' => 'two',
                'title' => 'Song Two',
                'artist' => 'Artist',
                'position' => 2,
                'status' => PlaylistTrackStatus::Pending->value,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $response = $this->getJson('/api/playlists');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.counts.total', 2)
            ->assertJsonPath('data.0.counts.downloaded', 1)
            ->assertJsonPath('data.0.counts.pending', 1);
    }

    public function test_show_playlist_includes_tracks(): void
    {
        $playlistId = $this->insertPlaylist(['title' => 'Rock Mix']);

        DB::table('saved_playlist_tracks')->insert([
            'saved_playlist_id' => $playlistId,
            'external_id' => 'abc',
            'title' => 'Track A',
            'artist' => 'Band',
            'position' => 1,
            'status' => PlaylistTrackStatus::Pending->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson('/api/playlists/'.$playlistId);

        $response
            ->assertOk()
            ->assertJsonPath('data.title', 'Rock Mix')
            ->assertJsonPath('data.tracks.0.external_id', 'abc')
            ->assertJsonPath('data.tracks.0.status', PlaylistTrackStatus::Pending->value);
    }

    public function test_update_playlist_settings(): void
    {
        $playlistId = $this->insertPlaylist();

        $response = $this->putJson('/api/playlists/'.$playlistId, [
            'sync_enabled' => false,
            'sync_interval_minutes' => 12,
            'default_format' => 'm4a',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.sync_enabled', false)
            ->assertJsonPath('data.sync_interval_minutes', 12)
            ->assertJsonPath('data.default_format', 'm4a');
    }

    public function test_delete_playlist(): void
    {
        $playlistId = $this->insertPlaylist(['title' => 'Arjona']);

        $response = $this->deleteJson('/api/playlists/'.$playlistId);

        $response->assertNoContent();
        $this->assertDatabaseMissing('saved_playlists', ['id' => $playlistId]);
    }

    public function test_delete_playlist_cancels_queue_jobs_and_removes_files(): void
    {
        $musicPath = storage_path('framework/testing/music-'.uniqid());
        config(['music.path' => $musicPath]);

        $playlistId = $this->insertPlaylist(['title' => 'Arjona']);
        $dir = $musicPath.'/playlists/'.$playlistId.'-arjona';
        mkdir($dir, 0755, true);
        $trackPath = $dir.'/01 - artist - song.mp3';
        file_put_contents($trackPath, 'audio');

        DB::table('saved_playlist_tracks')->insert([
            'saved_playlist_id' => $playlistId,
            'external_id' => 't1',
            'title' => 'Song',
            'artist' => 'Artist',
            'position' => 1,
            'status' => PlaylistTrackStatus::Downloaded->value,
            'file_path' => $trackPath,
            'last_error' => null,
            'first_seen_at' => now(),
            'downloaded_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $job = new \App\Jobs\ProcessPlaylistSyncJob($playlistId);
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => json_encode([
                'uuid' => 'test-uuid',
                'displayName' => \App\Jobs\ProcessPlaylistSyncJob::class,
                'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
                'data' => [
                    'commandName' => \App\Jobs\ProcessPlaylistSyncJob::class,
                    'command' => serialize($job),
                ],
            ]),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => time(),
            'created_at' => time(),
        ]);

        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertFileExists($trackPath);

        $response = $this->deleteJson('/api/playlists/'.$playlistId);

        $response->assertNoContent();
        $this->assertDatabaseMissing('saved_playlists', ['id' => $playlistId]);
        $this->assertDatabaseMissing('saved_playlist_tracks', ['saved_playlist_id' => $playlistId]);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertFileDoesNotExist($trackPath);
        $this->assertDirectoryDoesNotExist($dir);

        @rmdir($musicPath.'/playlists');
        @rmdir($musicPath);
    }

    public function test_sync_playlist_dispatches_job(): void
    {
        Queue::fake();
        $playlistId = $this->insertPlaylist();

        $response = $this->postJson('/api/playlists/'.$playlistId.'/sync');

        $response->assertAccepted();
        Queue::assertPushed(ProcessPlaylistSyncJob::class, fn (ProcessPlaylistSyncJob $job) => $job->savedPlaylistId === $playlistId);
    }

    public function test_sync_playlist_rejects_when_already_running(): void
    {
        Queue::fake();
        $playlistId = $this->insertPlaylist([
            'last_sync_status' => PlaylistSyncStatus::Running->value,
        ]);

        $response = $this->postJson('/api/playlists/'.$playlistId.'/sync');

        $response
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Playlist sync is already running.');

        Queue::assertNothingPushed();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function insertPlaylist(array $overrides = []): int
    {
        return (int) DB::table('saved_playlists')->insertGetId(array_merge([
            'provider' => 'youtube_music',
            'url' => 'https://music.youtube.com/playlist?list=PLtest',
            'title' => null,
            'sync_enabled' => true,
            'sync_interval_minutes' => 5,
            'default_format' => null,
            'last_synced_at' => null,
            'last_sync_status' => PlaylistSyncStatus::Idle->value,
            'last_sync_error' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }
}
