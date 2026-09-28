<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Music\ValueObjects\DownloadStatus;
use App\Jobs\ProcessDownloadJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DownloadApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_download_returns_accepted_with_job(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/downloads', [
            'url' => 'https://music.youtube.com/watch?v=dQw4w9WgXcQ',
            'format' => 'mp3_320',
        ]);

        $response
            ->assertAccepted()
            ->assertJsonPath('data.status', DownloadStatus::Pending->value)
            ->assertJsonPath('data.provider', 'youtube_music')
            ->assertJsonPath('data.kind', 'track')
            ->assertJsonPath('data.format', 'mp3_320');

        $jobId = (int) $response->json('data.id');
        $this->assertDatabaseHas('download_jobs', [
            'id' => $jobId,
            'status' => DownloadStatus::Pending->value,
        ]);

        Queue::assertPushed(ProcessDownloadJob::class, fn (ProcessDownloadJob $job) => $job->downloadJobId === $jobId);
    }

    public function test_create_download_detects_playlist_kind(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/downloads', [
            'url' => 'https://music.youtube.com/playlist?list=PLtest123',
        ]);

        $response
            ->assertAccepted()
            ->assertJsonPath('data.kind', 'playlist');
    }

    public function test_create_download_rejects_unsupported_url(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/downloads', [
            'url' => 'https://open.spotify.com/track/abc',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('message', 'No music provider supports this URL: https://open.spotify.com/track/abc');

        Queue::assertNothingPushed();
    }

    public function test_create_download_validates_url(): void
    {
        $response = $this->postJson('/api/downloads', [
            'url' => 'not-a-url',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['url']);
    }

    public function test_list_downloads_returns_recent_jobs(): void
    {
        DB::table('download_jobs')->insert([
            'provider' => 'youtube_music',
            'url' => 'https://music.youtube.com/watch?v=one',
            'kind' => 'track',
            'status' => DownloadStatus::Done->value,
            'progress' => 100,
            'error' => null,
            'options_json' => json_encode(['format' => 'mp3_320']),
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        DB::table('download_jobs')->insert([
            'provider' => 'youtube_music',
            'url' => 'https://music.youtube.com/watch?v=two',
            'kind' => 'track',
            'status' => DownloadStatus::Failed->value,
            'progress' => 0,
            'error' => 'Network error',
            'options_json' => json_encode(['format' => 'm4a']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson('/api/downloads');

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.status', DownloadStatus::Failed->value)
            ->assertJsonPath('data.0.error', 'Network error')
            ->assertJsonPath('data.1.status', DownloadStatus::Done->value);
    }

    public function test_show_download_returns_single_job(): void
    {
        $jobId = DB::table('download_jobs')->insertGetId([
            'provider' => 'youtube_music',
            'url' => 'https://music.youtube.com/watch?v=abc',
            'kind' => 'track',
            'status' => DownloadStatus::Running->value,
            'progress' => 50,
            'options_json' => json_encode(['format' => 'm4a']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson('/api/downloads/'.$jobId);

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $jobId)
            ->assertJsonPath('data.progress', 50)
            ->assertJsonPath('data.format', 'm4a');
    }

    public function test_show_download_returns_not_found_for_missing_job(): void
    {
        $response = $this->getJson('/api/downloads/999');

        $response->assertNotFound();
    }

    public function test_retry_download_requeues_failed_job(): void
    {
        Queue::fake();

        $jobId = DB::table('download_jobs')->insertGetId([
            'provider' => 'youtube_music',
            'url' => 'https://music.youtube.com/watch?v=abc',
            'kind' => 'track',
            'status' => DownloadStatus::Failed->value,
            'progress' => 0,
            'error' => 'Temporary failure',
            'options_json' => json_encode(['format' => 'mp3_320']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->postJson('/api/downloads/'.$jobId.'/retry');

        $response
            ->assertAccepted()
            ->assertJsonPath('data.status', DownloadStatus::Pending->value);

        $this->assertDatabaseHas('download_jobs', [
            'id' => $jobId,
            'status' => DownloadStatus::Pending->value,
        ]);

        Queue::assertPushed(ProcessDownloadJob::class, fn (ProcessDownloadJob $job) => $job->downloadJobId === $jobId);
    }

    public function test_retry_download_rejects_non_failed_job(): void
    {
        Queue::fake();

        $jobId = DB::table('download_jobs')->insertGetId([
            'provider' => 'youtube_music',
            'url' => 'https://music.youtube.com/watch?v=abc',
            'kind' => 'track',
            'status' => DownloadStatus::Done->value,
            'progress' => 100,
            'options_json' => json_encode(['format' => 'mp3_320']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->postJson('/api/downloads/'.$jobId.'/retry');

        $response
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Only failed downloads can be retried.');

        Queue::assertNothingPushed();
    }

    public function test_list_includes_title_artist_and_files_present(): void
    {
        $musicPath = storage_path('framework/testing/music-'.uniqid());
        mkdir($musicPath, 0777, true);
        config(['music.path' => $musicPath]);

        $file = $musicPath.'/artist/album/01 - song.mp3';
        mkdir(dirname($file), 0777, true);
        file_put_contents($file, 'x');

        DB::table('download_jobs')->insert([
            'provider' => 'youtube_music',
            'url' => 'https://music.youtube.com/watch?v=meta',
            'kind' => 'track',
            'title' => 'Song Title',
            'artist' => 'Artist Name',
            'status' => DownloadStatus::Done->value,
            'progress' => 100,
            'destination_path' => $file,
            'downloaded_paths' => json_encode([$file]),
            'options_json' => json_encode(['format' => 'mp3_320']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson('/api/downloads');

        $response
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Song Title')
            ->assertJsonPath('data.0.artist', 'Artist Name')
            ->assertJsonPath('data.0.files_present', true);

        @unlink($file);
        @rmdir(dirname($file));
        @rmdir(dirname(dirname($file)));
        @rmdir($musicPath);
    }

    public function test_files_present_false_when_file_missing(): void
    {
        config(['music.path' => storage_path('framework/testing')]);

        DB::table('download_jobs')->insert([
            'provider' => 'youtube_music',
            'url' => 'https://music.youtube.com/watch?v=gone',
            'kind' => 'track',
            'title' => 'Gone',
            'artist' => 'Nobody',
            'status' => DownloadStatus::Done->value,
            'progress' => 100,
            'destination_path' => storage_path('framework/testing/missing-file.mp3'),
            'downloaded_paths' => json_encode([storage_path('framework/testing/missing-file.mp3')]),
            'options_json' => json_encode(['format' => 'mp3_320']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson('/api/downloads');

        $response
            ->assertOk()
            ->assertJsonPath('data.0.files_present', false);
    }

    public function test_delete_download_removes_job_and_file(): void
    {
        $musicPath = storage_path('framework/testing/music-'.uniqid());
        mkdir($musicPath, 0777, true);
        config(['music.path' => $musicPath]);

        $file = $musicPath.'/artist/album/01 - song.mp3';
        mkdir(dirname($file), 0777, true);
        file_put_contents($file, 'x');

        $jobId = DB::table('download_jobs')->insertGetId([
            'provider' => 'youtube_music',
            'url' => 'https://music.youtube.com/watch?v=del',
            'kind' => 'track',
            'title' => 'Delete Me',
            'artist' => 'Artist',
            'status' => DownloadStatus::Done->value,
            'progress' => 100,
            'destination_path' => $file,
            'downloaded_paths' => json_encode([$file]),
            'options_json' => json_encode(['format' => 'mp3_320']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->deleteJson('/api/downloads/'.$jobId);

        $response->assertNoContent();
        $this->assertDatabaseMissing('download_jobs', ['id' => $jobId]);
        $this->assertFileDoesNotExist($file);

        @rmdir(dirname($file));
        @rmdir(dirname(dirname($file)));
        @rmdir($musicPath);
    }

    public function test_delete_download_succeeds_when_file_missing(): void
    {
        config(['music.path' => storage_path('framework/testing')]);

        $jobId = DB::table('download_jobs')->insertGetId([
            'provider' => 'youtube_music',
            'url' => 'https://music.youtube.com/watch?v=nofile',
            'kind' => 'track',
            'status' => DownloadStatus::Done->value,
            'progress' => 100,
            'destination_path' => storage_path('framework/testing/does-not-exist.mp3'),
            'options_json' => json_encode(['format' => 'mp3_320']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->deleteJson('/api/downloads/'.$jobId);

        $response->assertNoContent();
        $this->assertDatabaseMissing('download_jobs', ['id' => $jobId]);
    }

    public function test_clear_downloads_removes_all_jobs_and_files(): void
    {
        $musicPath = storage_path('framework/testing/music-'.uniqid());
        mkdir($musicPath, 0777, true);
        config(['music.path' => $musicPath]);

        $fileA = $musicPath.'/a.mp3';
        $fileB = $musicPath.'/b.mp3';
        file_put_contents($fileA, 'a');
        file_put_contents($fileB, 'b');

        DB::table('download_jobs')->insert([
            [
                'provider' => 'youtube_music',
                'url' => 'https://music.youtube.com/watch?v=a',
                'kind' => 'track',
                'status' => DownloadStatus::Done->value,
                'progress' => 100,
                'destination_path' => $fileA,
                'downloaded_paths' => json_encode([$fileA]),
                'options_json' => json_encode(['format' => 'mp3_320']),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'provider' => 'youtube_music',
                'url' => 'https://music.youtube.com/watch?v=b',
                'kind' => 'track',
                'status' => DownloadStatus::Done->value,
                'progress' => 100,
                'destination_path' => $fileB,
                'downloaded_paths' => json_encode([$fileB]),
                'options_json' => json_encode(['format' => 'mp3_320']),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $response = $this->deleteJson('/api/downloads');

        $response
            ->assertOk()
            ->assertJsonPath('deleted', 2);

        $this->assertDatabaseCount('download_jobs', 0);
        $this->assertFileDoesNotExist($fileA);
        $this->assertFileDoesNotExist($fileB);

        @rmdir($musicPath);
    }
}
