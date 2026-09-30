<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Music\Contracts\AudioReleaseYearProbe;
use App\Domain\Music\Contracts\DownloadedTrackRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BackfillReleaseYearCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_writes_the_year_read_from_the_date_tag(): void
    {
        $dir = storage_path('framework/testing/year-'.uniqid());
        mkdir($dir, 0777, true);
        $tagged = $dir.'/tagged.flac';
        $plain = $dir.'/plain.flac';
        $gone = $dir.'/gone.flac';
        file_put_contents($tagged, 'a');
        file_put_contents($plain, 'b');

        $index = app(DownloadedTrackRepository::class);
        $index->upsert(null, 'deezer', 'tagged', $tagged, 'Song', 'Artist');
        $index->upsert(null, 'deezer', 'plain', $plain, 'Other', 'Artist');
        $index->upsert(null, 'deezer', 'gone', $gone, 'Missing', 'Artist');
        $index->upsert(null, 'deezer', 'known', $tagged, 'Known', 'Artist', releaseYear: 1999);

        $this->app->instance(AudioReleaseYearProbe::class, new class implements AudioReleaseYearProbe
        {
            public function releaseYear(string $filePath): ?int
            {
                return str_ends_with($filePath, 'tagged.flac') ? 2001 : null;
            }
        });

        $this->artisan('downloads:backfill-release-year')
            ->expectsOutputToContain('set 2001')
            ->expectsOutputToContain('has no date tag')
            ->expectsOutputToContain('file missing')
            ->assertSuccessful();

        $this->assertSame(2001, (int) DB::table('downloaded_tracks')->where('external_id', 'tagged')->value('release_year'));
        $this->assertNull(DB::table('downloaded_tracks')->where('external_id', 'plain')->value('release_year'));
        $this->assertSame(1999, (int) DB::table('downloaded_tracks')->where('external_id', 'known')->value('release_year'));

        unlink($tagged);
        unlink($plain);
        @rmdir($dir);
    }
}
