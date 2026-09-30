<?php

declare(strict_types=1);

namespace App\Application\DeleteDownload;

use App\Domain\Music\Contracts\DownloadedTrackRepository;
use App\Domain\Music\Contracts\DownloadJobRepository;
use App\Infrastructure\Storage\DownloadedFilesCleanup;

final readonly class DeleteDownloadCommand
{
    public function __construct(
        public int $id,
    ) {}
}

final readonly class DeleteDownloadHandler
{
    public function __construct(
        private DownloadJobRepository $jobs,
        private DownloadedFilesCleanup $cleanup,
        private DownloadedTrackRepository $downloadedTracks,
    ) {}

    public function handle(DeleteDownloadCommand $command): bool
    {
        $job = $this->jobs->find($command->id);
        if ($job === null) {
            return false;
        }

        $this->deleteOwnedFiles($job);

        return $this->jobs->delete($command->id);
    }

    /**
     * @param  array<string, mixed>  $job
     */
    private function deleteOwnedFiles(array $job): void
    {
        $jobId = (int) $job['id'];
        $preserve = [];
        foreach ($this->cleanup->pathsForJob($job) as $path) {
            if ($this->downloadedTracks->isReferencedByAnotherOwner($path, $jobId)) {
                $preserve[] = $path;
            }
        }

        $this->cleanup->deleteJobFiles($job, $preserve);
        $this->downloadedTracks->deleteByJobId($jobId);
    }
}
