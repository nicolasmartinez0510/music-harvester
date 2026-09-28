<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Music\Contracts\DownloadJobRepository;
use App\Domain\Music\ValueObjects\DownloadStatus;
use App\Jobs\ProcessDownloadJob;
use Illuminate\Console\Command;

class ProcessDownloadCommand extends Command
{
    protected $signature = 'music:process {id : Download job id from download_jobs table}';

    protected $description = 'Process a pending or failed download job synchronously';

    public function handle(DownloadJobRepository $jobs): int
    {
        $jobId = (int) $this->argument('id');
        $job = $jobs->find($jobId);

        if ($job === null) {
            $this->error("Download job #{$jobId} not found.");

            return self::FAILURE;
        }

        if ($job['status'] === DownloadStatus::Done->value) {
            $this->warn("Download job #{$jobId} is already done.");

            return self::SUCCESS;
        }

        if ($job['status'] === DownloadStatus::Running->value) {
            $this->warn("Download job #{$jobId} is already running.");

            return self::FAILURE;
        }

        $this->info("Processing download job #{$jobId}...");

        try {
            dispatch_sync(new ProcessDownloadJob($jobId));
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $updated = $jobs->find($jobId);
        $status = $updated['status'] ?? 'unknown';
        $path = $updated['destination_path'] ?? null;

        if ($status === DownloadStatus::Done->value) {
            $this->info('Download completed successfully.');
            if (is_string($path) && $path !== '') {
                $this->line("Saved to: {$path}");
            }

            return self::SUCCESS;
        }

        $error = $updated['error'] ?? 'Unknown error';
        $this->error("Download failed: {$error}");

        return self::FAILURE;
    }
}
