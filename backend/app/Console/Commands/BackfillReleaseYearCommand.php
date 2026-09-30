<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Music\Contracts\AudioReleaseYearProbe;
use App\Domain\Music\Contracts\DownloadedTrackRepository;
use Illuminate\Console\Command;
use Throwable;

final class BackfillReleaseYearCommand extends Command
{
    protected $signature = 'downloads:backfill-release-year
        {--dry-run : Report years found in tags without writing them}';

    protected $description = 'Fill release_year on indexed tracks from the date tag already written on the file';

    public function handle(DownloadedTrackRepository $index, AudioReleaseYearProbe $probe): int
    {
        set_time_limit(0);

        $dryRun = (bool) $this->option('dry-run');
        $updated = 0;
        $missingFile = 0;
        $withoutYear = 0;
        $failed = 0;

        foreach ($index->listMissingReleaseYear() as $row) {
            $id = (int) ($row['id'] ?? 0);
            $path = $row['file_path'] ?? null;
            if ($id === 0 || ! is_string($path) || $path === '' || ! is_file($path)) {
                $missingFile++;
                $this->line(sprintf('Track #%d skipped: file missing', $id));

                continue;
            }

            try {
                $year = $probe->releaseYear($path);
            } catch (Throwable $exception) {
                $failed++;
                $this->warn(sprintf('Track #%d tag read failed: %s', $id, $exception->getMessage()));

                continue;
            }

            if ($year === null) {
                $withoutYear++;
                $this->line(sprintf('Track #%d has no date tag', $id));

                continue;
            }

            if (! $dryRun) {
                $index->setReleaseYear($id, $year);
            }
            $updated++;
            $this->line(sprintf('Track #%d %s %d', $id, $dryRun ? 'would set' : 'set', $year));
        }

        $this->info(sprintf(
            '%s %d, no date tag %d, missing file %d, failed %d.',
            $dryRun ? 'Dry run. Would set' : 'Set',
            $updated,
            $withoutYear,
            $missingFile,
            $failed,
        ));

        return self::SUCCESS;
    }
}