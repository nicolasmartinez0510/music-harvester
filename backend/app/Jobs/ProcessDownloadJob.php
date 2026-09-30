<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Auth\LibraryPathResolver;
use App\Application\Settings\ProviderSettingsResolver;
use App\Domain\Music\Contracts\DownloadedTrackRepository;
use App\Domain\Music\Contracts\DownloadJobRepository;
use App\Domain\Music\Contracts\TrackMetadataApplicator;
use App\Domain\Music\Models\Track;
use App\Domain\Music\ValueObjects\AudioFormat;
use App\Domain\Music\ValueObjects\DownloadOptions;
use App\Domain\Music\ValueObjects\DownloadStatus;
use App\Domain\Music\ValueObjects\MetadataEnrichContext;
use App\Domain\Music\ValueObjects\ResolvedKind;
use App\Domain\Music\ValueObjects\ResolvedMusic;
use App\Infrastructure\Providers\MusicProviderRegistry;
use App\Infrastructure\Providers\YoutubeMusic\YoutubeMusicProvider;
use App\Infrastructure\Storage\DownloadedFilesCleanup;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
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
        ProviderSettingsResolver $settings,
        TrackMetadataApplicator $metadata,
        DownloadedFilesCleanup $cleanup,
        DownloadedTrackRepository $downloadedTracks,
    ): void {
        $job = $jobs->find($this->downloadJobId);

        if ($job === null) {
            return;
        }

        // Only skip completed jobs. Allow re-entry when status is still "running"
        // (worker crash/timeout left the row stuck and queue retries would no-op).
        if (in_array($job['status'], [DownloadStatus::Done->value, DownloadStatus::Existing->value], true)) {
            return;
        }

        $jobs->updateStatus($this->downloadJobId, DownloadStatus::Running);

        try {
            $providerName = is_string($job['provider'] ?? null) ? $job['provider'] : null;
            $provider = $providerName !== null
                ? ($providers->findByName($providerName) ?? $providers->resolveForUrl($job['url']))
                : $providers->resolveForUrl($job['url']);

            if ($provider === null) {
                throw new RuntimeException('No provider supports URL: '.$job['url']);
            }

            $options = $this->buildOptions($job, $provider->name(), $settings);
            if ($provider instanceof YoutubeMusicProvider) {
                $provider = $provider->usingCookies($options->cookiesPath);
            }
            $resolved = $provider->resolve($job['url']);
            $items = $resolved->items;
            $total = count($items);

            if ($total === 0) {
                throw new RuntimeException('No items found to download.');
            }

            $jobs->updateMetadata(
                $this->downloadJobId,
                $resolved->title,
                $this->resolveArtist($resolved),
            );

            $completed = 0;
            $actuallyDownloaded = 0;
            $reused = 0;
            $lastPath = null;
            $failures = [];
            $isMultiItem = $this->isMultiItemJob($job);
            $userId = isset($job['user_id']) && $job['user_id'] !== null ? (int) $job['user_id'] : null;

            foreach ($items as $item) {
                $track = $item->item instanceof Track ? $item->item : null;
                $externalId = $track?->id;
                if (is_string($externalId) && $externalId !== '') {
                    $existing = $downloadedTracks->findPresent($userId, $provider->name(), $externalId);
                    if ($existing !== null) {
                        $completed++;
                        $reused++;
                        $existingPath = is_string($existing['file_path'] ?? null) ? $existing['file_path'] : '';
                        $progress = (int) round(($completed / $total) * 100);
                        if ($existingPath !== '') {
                            $lastPath = $existingPath;
                            $jobs->updateProgress($this->downloadJobId, $progress, $existingPath);
                            $jobs->appendDownloadedPath($this->downloadJobId, $existingPath);
                            $cleanup->relaxPermissions($existingPath);
                        } else {
                            $jobs->updateProgress($this->downloadJobId, $progress);
                        }

                        continue;
                    }
                }

                if ($isMultiItem && $actuallyDownloaded > 0) {
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
                $actuallyDownloaded++;
                $lastPath = $result->destinationPath;
                if ($provider->name() === 'deezer' && $item->item instanceof Track && is_string($lastPath) && $lastPath !== '') {
                    try {
                        $metadata->handle(
                            $lastPath,
                            $item->item,
                            'deezer',
                            new MetadataEnrichContext(
                                arl: $options->deezerArl,
                                kind: $resolved->kind,
                                trackTotal: $resolved->kind === ResolvedKind::Playlist ? $total : null,
                            ),
                        );
                    } catch (Throwable $exception) {
                        Log::warning('audio metadata enrich failed', [
                            'path' => $lastPath,
                            'error' => $exception->getMessage(),
                        ]);
                    }
                }
                $progress = (int) round(($completed / $total) * 100);
                $jobs->updateProgress($this->downloadJobId, $progress, $lastPath);
                if (is_string($lastPath) && $lastPath !== '') {
                    $jobs->appendDownloadedPath($this->downloadJobId, $lastPath);
                    $cleanup->relaxPermissions($lastPath);
                    if ($track instanceof Track && is_string($track->id) && $track->id !== '') {
                        $downloadedTracks->upsert(
                            userId: $userId,
                            provider: $provider->name(),
                            externalId: $track->id,
                            filePath: $lastPath,
                            title: $track->title,
                            artist: $track->artist?->name,
                            downloadJobId: $this->downloadJobId,
                            savedPlaylistTrackId: null,
                        );
                    }
                }
            }

            if ($completed === 0) {
                throw new RuntimeException($failures[0] ?? 'Download failed.');
            }

            $summary = $this->buildCompletionSummary($completed, $total, $failures);
            $status = $actuallyDownloaded === 0 && $reused > 0 && $failures === []
                ? DownloadStatus::Existing
                : DownloadStatus::Done;
            $jobs->updateStatus($this->downloadJobId, $status, $summary);
        } catch (Throwable $exception) {
            $jobs->updateStatus($this->downloadJobId, DownloadStatus::Failed, $exception->getMessage());

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $job
     */
    private function buildOptions(array $job, string $provider, ProviderSettingsResolver $settings): DownloadOptions
    {
        $optionsJson = json_decode((string) ($job['options_json'] ?? '{}'), true);
        $formatValue = is_array($optionsJson) ? ($optionsJson['format'] ?? null) : null;
        $format = AudioFormat::tryFrom((string) ($formatValue ?? $settings->defaultFormat()->value))
            ?? $settings->defaultFormat();

        $userId = isset($job['user_id']) && $job['user_id'] !== null ? (int) $job['user_id'] : null;
        $destination = is_string($job['download_destination'] ?? null) && $job['download_destination'] !== ''
            ? (string) $job['download_destination']
            : 'server';
        $user = $userId !== null ? User::query()->find($userId) : null;
        $root = app(LibraryPathResolver::class)->outputRoot($user, $destination, $this->downloadJobId);

        return new DownloadOptions(
            format: $format,
            musicPath: $root,
            provider: $provider,
            cookiesPath: $settings->youtubeMusicCookiesPath($userId),
            deezerArl: $settings->deezerArl($userId),
            deezerMode: $settings->deezerMode(),
        );
    }

    /**
     * @param  array<string, mixed>  $job
     */
    private function isMultiItemJob(array $job): bool
    {
        return in_array($job['kind'] ?? '', ['playlist', 'album'], true);
    }

    private function resolveArtist(ResolvedMusic $resolved): ?string
    {
        if ($resolved->kind === ResolvedKind::Playlist) {
            return null;
        }

        $first = $resolved->items[0]->item ?? null;
        if ($first instanceof Track) {
            $name = $first->artist?->name;

            return is_string($name) && $name !== '' ? $name : null;
        }

        return null;
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
