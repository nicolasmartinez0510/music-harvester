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
}
