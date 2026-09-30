<?php

declare(strict_types=1);

namespace App\Application\ClearDownloads;

use App\Domain\Music\Contracts\DownloadedTrackRepository;
use App\Domain\Music\Contracts\DownloadJobRepository;
use App\Infrastructure\Storage\DownloadedFilesCleanup;

final readonly class ClearDownloadsCommand
{
    public function __construct(
        public ?int $userId = null,
        public bool $includeUnowned = false,
    ) {}
}

final readonly class ClearDownloadsHandler
{
    public function __construct(
        private DownloadJobRepository $jobs,
        private DownloadedFilesCleanup $cleanup,
        private DownloadedTrackRepository $downloadedTracks,
    ) {}

    public function handle(ClearDownloadsCommand $command): int
    {
        $jobs = $this->jobs->listAll($command->userId, $command->includeUnowned);

        foreach ($jobs as $job) {
            $jobId = (int) $job['id'];
            $preserve = [];
            foreach ($this->cleanup->pathsForJob($job) as $path) {
                if ($this->downloadedTracks->isReferencedByAnotherOwner($path, $jobId)) {
                    $preserve[] = $path;
                }
            }

            $this->cleanup->deleteJobFiles($job, $preserve);
            $this->downloadedTracks->deleteByJobId($jobId);
            $this->jobs->delete($jobId);
        }

        return count($jobs);
    }
}
