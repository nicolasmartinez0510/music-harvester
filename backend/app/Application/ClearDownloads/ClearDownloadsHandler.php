<?php

declare(strict_types=1);

namespace App\Application\ClearDownloads;

use App\Domain\Music\Contracts\DownloadJobRepository;
use App\Infrastructure\Storage\DownloadedFilesCleanup;

final readonly class ClearDownloadsCommand
{
}

final readonly class ClearDownloadsHandler
{
    public function __construct(
        private DownloadJobRepository $jobs,
        private DownloadedFilesCleanup $cleanup,
    ) {}

    public function handle(ClearDownloadsCommand $command): int
    {
        $jobs = $this->jobs->listAll();

        foreach ($jobs as $job) {
            $this->cleanup->deleteJobFiles($job);
        }

        return $this->jobs->deleteAll();
    }
}
