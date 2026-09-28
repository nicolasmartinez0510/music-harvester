<?php

declare(strict_types=1);

namespace App\Application\DeleteDownload;

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
    ) {}

    public function handle(DeleteDownloadCommand $command): bool
    {
        $job = $this->jobs->find($command->id);
        if ($job === null) {
            return false;
        }

        $this->cleanup->deleteJobFiles($job);

        return $this->jobs->delete($command->id);
    }
}
