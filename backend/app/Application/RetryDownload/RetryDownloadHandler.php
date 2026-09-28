<?php

declare(strict_types=1);

namespace App\Application\RetryDownload;

use App\Domain\Music\Contracts\DownloadJobRepository;
use App\Domain\Music\ValueObjects\DownloadStatus;
use App\Jobs\ProcessDownloadJob;

final readonly class RetryDownloadCommand
{
    public function __construct(
        public int $jobId,
    ) {}
}

final readonly class RetryDownloadHandler
{
    public function __construct(
        private DownloadJobRepository $jobs,
    ) {}

    public function handle(RetryDownloadCommand $command): bool
    {
        $job = $this->jobs->find($command->jobId);

        if ($job === null) {
            return false;
        }

        if ($job['status'] !== DownloadStatus::Failed->value) {
            return false;
        }

        $this->jobs->updateStatus($command->jobId, DownloadStatus::Pending);
        ProcessDownloadJob::dispatch($command->jobId);

        return true;
    }
}
