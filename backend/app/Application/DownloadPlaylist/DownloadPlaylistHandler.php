<?php

declare(strict_types=1);

namespace App\Application\DownloadPlaylist;

use App\Domain\Music\Contracts\DownloadJobRepository;
use App\Domain\Music\ValueObjects\AudioFormat;
use App\Domain\Music\ValueObjects\MusicUrl;
use App\Jobs\ProcessDownloadJob;

final readonly class DownloadPlaylistCommand
{
    public function __construct(
        public MusicUrl $url,
        public AudioFormat $format,
    ) {}
}

final readonly class DownloadPlaylistHandler
{
    public function __construct(
        private DownloadJobRepository $jobs,
    ) {}

    public function handle(DownloadPlaylistCommand $command): int
    {
        $jobId = $this->jobs->create(
            provider: 'youtube_music',
            url: (string) $command->url,
            kind: 'playlist',
            format: $command->format,
        );

        ProcessDownloadJob::dispatch($jobId);

        return $jobId;
    }
}
