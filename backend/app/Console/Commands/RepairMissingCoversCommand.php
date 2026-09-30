<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Metadata\RepairMissingCoversHandler;
use Illuminate\Console\Command;

final class RepairMissingCoversCommand extends Command
{
    protected $signature = 'downloads:repair-covers
        {--sleep=0 : Seconds to wait between cover repairs}
        {--dry-run : Report files missing a cover without writing tags}';

    protected $description = 'Embed Deezer covers on indexed downloads that are missing artwork';

    public function handle(RepairMissingCoversHandler $handler): int
    {
        set_time_limit(0);

        $dryRun = (bool) $this->option('dry-run');
        $sleep = max(0, (int) $this->option('sleep'));

        $result = $handler->handle($dryRun, $sleep, function (string $line): void {
            $this->line($line);
        });

        if ($dryRun) {
            $this->info(sprintf(
                'Dry run. Scanned %d, already had cover %d, missing file %d, unsupported %d, would repair %d, failed %d.',
                $result->scanned,
                $result->alreadyCovered,
                $result->missingFile,
                $result->unsupported,
                $result->stillMissing,
                $result->failed,
            ));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Scanned %d, already had cover %d, missing file %d, unsupported %d, repaired %d, still missing %d, failed %d.',
            $result->scanned,
            $result->alreadyCovered,
            $result->missingFile,
            $result->unsupported,
            $result->repaired,
            $result->stillMissing,
            $result->failed,
        ));

        return self::SUCCESS;
    }
}
