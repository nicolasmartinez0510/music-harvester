<?php

declare(strict_types=1);

namespace App\Application\CreateDownload;

use App\Domain\Music\Contracts\DownloadJobRepository;
use App\Domain\Music\Contracts\MusicProvider;
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
        public ?string $provider = null,
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
        $provider = $this->resolveProvider($url, $command->provider);

        if ($provider === null) {
            throw UnsupportedMusicUrlException::forUrl($url);
        }

        if ($command->provider !== null && ! $provider->supports($url)) {
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

    private function resolveProvider(string $url, ?string $providerName): ?MusicProvider
    {
        if ($providerName !== null && $providerName !== '' && $providerName !== 'auto') {
            return $this->providers->findByName($providerName);
        }

        return $this->providers->resolveForUrl($url);
    }

    private function inferKind(string $url): string
    {
        if (preg_match('#music\.youtube\.com/browse/#i', $url)) {
            return 'album';
        }

        if (preg_match('#deezer\.com(?:/[a-z]{2})?/(album)/#i', $url)) {
            return 'album';
        }

        if (preg_match('#deezer\.com(?:/[a-z]{2})?/(playlist)/#i', $url)
            || preg_match('#[?&]list=#i', $url)
            || preg_match('#/(playlist|list)/#i', $url)) {
            return 'playlist';
        }

        return 'track';
    }
}
