<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\DeleteDownload\DeleteDownloadCommand;
use App\Application\DeleteDownload\DeleteDownloadHandler;
use App\Domain\Music\Contracts\DownloadedTrackRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DownloadedTrackIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_find_present_returns_existing_file_and_drops_missing_ones(): void
    {
        $dir = storage_path('framework/testing/index-'.uniqid());
        mkdir($dir, 0777, true);
        $file = $dir.'/01 - song.flac';
        file_put_contents($file, 'a');

        $index = app(DownloadedTrackRepository::class);
        $index->upsert(null, 'deezer', '100', $file, 'Song', 'Artist', 9);

        $present = $index->findPresent(null, 'deezer', '100');
        $this->assertNotNull($present);
        $this->assertSame($file, $present['file_path']);

        unlink($file);
        $this->assertNull($index->findPresent(null, 'deezer', '100'));
        $this->assertDatabaseMissing('downloaded_tracks', [
            'provider' => 'deezer',
            'external_id' => '100',
        ]);

        @rmdir($dir);
    }

    public function test_upsert_updates_the_same_owner_track(): void
    {
        $index = app(DownloadedTrackRepository::class);
        $index->upsert(null, 'deezer', '100', '/music/a.flac', 'Song', 'Artist', 1);
        $index->upsert(null, 'deezer', '100', '/music/b.flac', 'Song', 'Artist', 2);

        $this->assertSame(1, DB::table('downloaded_tracks')->count());
        $this->assertDatabaseHas('downloaded_tracks', [
            'external_id' => '100',
            'file_path' => '/music/b.flac',
            'download_job_id' => 2,
        ]);
    }

    public function test_delete_job_keeps_file_owned_by_another_job_and_clears_own_index(): void
    {
        $musicPath = storage_path('framework/testing/music-'.uniqid());
        mkdir($musicPath.'/album', 0777, true);
        config(['music.path' => $musicPath]);

        $shared = $musicPath.'/album/01 - shared.flac';
        $own = $musicPath.'/album/02 - own.flac';
        file_put_contents($shared, 'a');
        file_put_contents($own, 'b');

        $ownerId = DB::table('download_jobs')->insertGetId($this->jobRow([$shared]));
        $otherId = DB::table('download_jobs')->insertGetId($this->jobRow([$shared, $own]));

        $index = app(DownloadedTrackRepository::class);
        $index->upsert(null, 'deezer', 'shared', $shared, 'Shared', 'Artist', $ownerId);
        $index->upsert(null, 'deezer', 'own', $own, 'Own', 'Artist', $otherId);

        $deleted = app(DeleteDownloadHandler::class)->handle(new DeleteDownloadCommand($otherId));

        $this->assertTrue($deleted);
        $this->assertFileExists($shared);
        $this->assertFileDoesNotExist($own);
        $this->assertDatabaseMissing('download_jobs', ['id' => $otherId]);
        $this->assertDatabaseHas('downloaded_tracks', [
            'external_id' => 'shared',
            'download_job_id' => $ownerId,
        ]);
        $this->assertDatabaseMissing('downloaded_tracks', [
            'external_id' => 'own',
        ]);

        @unlink($shared);
        @rmdir($musicPath.'/album');
        @rmdir($musicPath);
    }

    /**
     * @param  list<string>  $paths
     * @return array<string, mixed>
     */
    private function jobRow(array $paths): array
    {
        return [
            'provider' => 'deezer',
            'url' => 'https://www.deezer.com/album/'.uniqid(),
            'kind' => 'album',
            'status' => 'done',
            'progress' => 100,
            'destination_path' => $paths[0],
            'downloaded_paths' => json_encode($paths),
            'options_json' => json_encode(['format' => 'flac']),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
