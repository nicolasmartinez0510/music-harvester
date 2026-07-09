<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Music\Contracts\DownloadJobRepository;
use App\Domain\Music\Models\Track;
use App\Domain\Music\ValueObjects\AudioFormat;
use App\Domain\Music\ValueObjects\DownloadOptions;
use App\Domain\Music\ValueObjects\DownloadStatus;
use App\Infrastructure\Providers\MusicProviderRegistry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

class ProcessDownloadJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    private const PLAYLIST_TRACK_DELAY_SECONDS = 2;

    public function __construct(
        public int $downloadJobId,
    ) {}

    public function handle(
        DownloadJobRepository $jobs,
        MusicProviderRegistry $providers,
    ): void {
        $job = $jobs->find($this->downloadJobId);

        if ($job === null) {
            return;
        }

        if (in_array($job['status'], [DownloadStatus::Done->value, DownloadStatus::Running->value], true)) {
            return;
        }

        $jobs->updateStatus($this->downloadJobId, DownloadStatus::Running);

        try {
            $provider = $providers->resolveForUrl($job['url']);

            if ($provider === null) {
                throw new RuntimeException('No provider supports URL: '.$job['url']);
            }

            $options = $this->buildOptions($job);
            $resolved = $provider->resolve($job['url']);
            $items = $resolved->items;
            $total = count($items);

            if ($total === 0) {
                throw new RuntimeException('No items found to download.');
            }

            $completed = 0;
            $lastPath = null;
            $failures = [];
            $isMultiItem = $this->isMultiItemJob($job);

            foreach ($items as $item) {
                if ($isMultiItem && $completed > 0) {
                    sleep(self::PLAYLIST_TRACK_DELAY_SECONDS);
                }

                $result = $provider->download($item, $options);

                if (! $result->success) {
                    if ($isMultiItem) {
                        $trackTitle = $item->item instanceof Track
                            ? $item->item->title
                            : 'Unknown track';
                        $failures[] = sprintf('%s: %s', $trackTitle, $result->error ?? 'Download failed.');

                        continue;
                    }

                    throw new RuntimeException($result->error ?? 'Download failed.');
                }

                $completed++;
                $lastPath = $result->destinationPath;
                $progress = (int) round(($completed / $total) * 100);
                $jobs->updateProgress($this->downloadJobId, $progress, $lastPath);
            }

            if ($completed === 0) {
                throw new RuntimeException($failures[0] ?? 'Download failed.');
            }

            $summary = $this->buildCompletionSummary($completed, $total, $failures);
            $jobs->updateStatus($this->downloadJobId, DownloadStatus::Done, $summary);
        } catch (Throwable $exception) {
            $jobs->updateStatus($this->downloadJobId, DownloadStatus::Failed, $exception->getMessage());

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $job
     */
    private function buildOptions(array $job): DownloadOptions
    {
        $optionsJson = json_decode((string) ($job['options_json'] ?? '{}'), true);
        $formatValue = is_array($optionsJson) ? ($optionsJson['format'] ?? null) : null;
        $format = AudioFormat::tryFrom((string) ($formatValue ?? config('music.default_format')))
            ?? AudioFormat::Mp3_320;

        $cookiesPath = config('music.cookies_path');

        return new DownloadOptions(
            format: $format,
            musicPath: config('music.path'),
            cookiesPath: is_string($cookiesPath) && $cookiesPath !== '' ? $cookiesPath : null,
        );
    }

    /**
     * @param  array<string, mixed>  $job
     */
    private function isMultiItemJob(array $job): bool
    {
        return in_array($job['kind'] ?? '', ['playlist', 'album'], true);
    }

    /**
     * @param  list<string>  $failures
     */
    private function buildCompletionSummary(int $completed, int $total, array $failures): ?string
    {
        if ($failures === []) {
            return null;
        }

        $shownFailures = array_slice($failures, 0, 3);
        $summary = sprintf(
            'Completed %d/%d tracks. %d failed: %s',
            $completed,
            $total,
            count($failures),
            implode(' | ', $shownFailures),
        );

        if (count($failures) > 3) {
            $summary .= sprintf(' | …and %d more', count($failures) - 3);
        }

        return $summary;
    }
}
