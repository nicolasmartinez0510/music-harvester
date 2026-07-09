<?php

declare(strict_types=1);

namespace App\Application\CreateDownload;

use App\Domain\Music\Contracts\DownloadJobRepository;
use App\Domain\Music\Exceptions\UnsupportedMusicUrlException;
use App\Domain\Music\ValueObjects\AudioFormat;
use App\Domain\Music\ValueObjects\MusicUrl;
use App\Infrastructure\Providers\MusicProviderRegistry;
use App\Jobs\ProcessDownloadJob;

final readonly class CreateDownloadCommand
{
    public function __construct(
        public MusicUrl $url,
        public AudioFormat $format,
    ) {}
}

final readonly class CreateDownloadHandler
{
    public function __construct(
        private DownloadJobRepository $jobs,
        private MusicProviderRegistry $providers,
    ) {}

    public function handle(CreateDownloadCommand $command): int
    {
        $url = (string) $command->url;
        $provider = $this->providers->resolveForUrl($url);

        if ($provider === null) {
            throw UnsupportedMusicUrlException::forUrl($url);
        }

        $jobId = $this->jobs->create(
            provider: $provider->name(),
            url: $url,
            kind: $this->inferKind($url),
            format: $command->format,
        );

        ProcessDownloadJob::dispatch($jobId);

        return $jobId;
    }

    private function inferKind(string $url): string
    {
        if (preg_match('#music\.youtube\.com/browse/#i', $url)) {
            return 'album';
        }

        if (preg_match('#[?&]list=#i', $url)) {
            return 'playlist';
        }

        return 'track';
    }
}
