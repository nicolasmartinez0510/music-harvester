<?php

declare(strict_types=1);

namespace App\Application\DownloadTrack;

use App\Domain\Music\Contracts\DownloadJobRepository;
use App\Domain\Music\ValueObjects\AudioFormat;
use App\Domain\Music\ValueObjects\MusicUrl;
use App\Jobs\ProcessDownloadJob;

final readonly class DownloadTrackCommand
{
    public function __construct(
        public MusicUrl $url,
        public AudioFormat $format,
    ) {}
}

final readonly class DownloadTrackHandler
{
    public function __construct(
        private DownloadJobRepository $jobs,
    ) {}

    public function handle(DownloadTrackCommand $command): int
    {
        $jobId = $this->jobs->create(
            provider: 'youtube_music',
            url: (string) $command->url,
            kind: 'track',
            format: $command->format,
        );

        ProcessDownloadJob::dispatch($jobId);

        return $jobId;
    }
}
