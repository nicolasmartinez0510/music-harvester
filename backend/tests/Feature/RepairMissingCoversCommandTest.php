<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Music\Contracts\AudioCoverProbe;
use App\Domain\Music\Contracts\DownloadedTrackRepository;
use App\Domain\Music\Contracts\TrackMetadataApplicator;
use App\Domain\Music\Models\Track;
use App\Domain\Music\ValueObjects\MetadataEnrichContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepairMissingCoversCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_repairs_indexed_deezer_files_that_lack_a_cover(): void
    {
        $dir = storage_path('framework/testing/covers-'.uniqid());
        mkdir($dir, 0777, true);
        $missing = $dir.'/missing.mp3';
        $present = $dir.'/present.flac';
        $gone = $dir.'/gone.mp3';
        file_put_contents($missing, 'x');
        file_put_contents($present, 'x');

        $index = app(DownloadedTrackRepository::class);
        $index->upsert(null, 'deezer', '1', $missing, 'Without Cover', 'Artist');
        $index->upsert(null, 'deezer', '2', $present, 'With Cover', 'Artist');
        $index->upsert(null, 'deezer', '3', $gone, 'Missing File', 'Artist');
        $index->upsert(null, 'youtube_music', '9', $missing, 'YouTube', 'Artist');

        $state = new class
        {
            public bool $embedded = false;

            /** @var list<string> */
            public array $paths = [];

            public ?string $provider = null;

            public ?string $trackId = null;
        };
        $this->app->instance(AudioCoverProbe::class, new class($state) implements AudioCoverProbe
        {
            public function __construct(private object $state) {}

            public function hasCover(string $filePath): bool
            {
                if (str_ends_with($filePath, 'present.flac')) {
                    return true;
                }

                return $this->state->embedded;
            }
        });
        $this->app->instance(TrackMetadataApplicator::class, new class($state) implements TrackMetadataApplicator
        {
            public function __construct(private object $state) {}

            public function handle(string $filePath, Track $track, string $provider, MetadataEnrichContext $context): void
            {
                $this->state->paths[] = $filePath;
                $this->state->provider = $provider;
                $this->state->trackId = $track->id;
                $this->state->embedded = true;
            }
        });

        $this->artisan('downloads:repair-covers', ['--dry-run' => true])
            ->expectsOutputToContain('would repair '.$missing)
            ->expectsOutputToContain('would repair 1')
            ->assertSuccessful();
        $this->assertSame([], $state->paths);

        $this->artisan('downloads:repair-covers')
            ->expectsOutputToContain('repaired '.$missing)
            ->expectsOutputToContain('repaired 1')
            ->assertSuccessful();
        $this->assertSame([$missing], $state->paths);
        $this->assertSame('deezer', $state->provider);
        $this->assertSame('1', $state->trackId);

        @unlink($missing);
        @unlink($present);
        @rmdir($dir);
    }
}
