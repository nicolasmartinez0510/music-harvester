<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\IndexDownloadedTracks\DownloadedTrackFileMatcher;
use App\Application\Settings\ProviderSettingsResolver;
use App\Domain\Music\Contracts\DownloadedTrackRepository;
use App\Domain\Music\Contracts\DownloadJobRepository;
use App\Domain\Music\Contracts\SavedPlaylistRepository;
use App\Domain\Music\Models\Track;
use App\Domain\Music\ValueObjects\DownloadStatus;
use App\Domain\Music\ValueObjects\PlaylistTrackStatus;
use App\Infrastructure\Providers\MusicProviderRegistry;
use App\Infrastructure\Providers\YoutubeMusic\YoutubeMusicProvider;
use App\Infrastructure\Storage\DownloadedFilesCleanup;
use Illuminate\Console\Command;
use Throwable;

final class BackfillDownloadedTrackIndexCommand extends Command
{
    protected $signature = 'downloads:backfill-index
        {--sleep=1 : Seconds to wait between provider resolves}
        {--dry-run : Report matches without writing the index}';

    protected $description = 'Index already-downloaded tracks so playlist sync can skip them';

    public function handle(
        DownloadJobRepository $jobs,
        SavedPlaylistRepository $playlists,
        DownloadedTrackRepository $index,
        DownloadedTrackFileMatcher $matcher,
        DownloadedFilesCleanup $cleanup,
        MusicProviderRegistry $providers,
        ProviderSettingsResolver $settings,
    ): int {
        set_time_limit(0);

        $dryRun = (bool) $this->option('dry-run');
        $sleep = max(0, (int) $this->option('sleep'));
        $resolvedCache = [];

        $indexed = 0;
        $already = 0;
        $missing = 0;
        $resolveErrors = 0;

        $doneJobs = array_values(array_filter(
            $jobs->listAll(),
            fn (array $job): bool => ($job['status'] ?? '') === DownloadStatus::Done->value,
        ));
        usort($doneJobs, function (array $a, array $b): int {
            $rank = ['track' => 0, 'album' => 1, 'playlist' => 2];
            $left = $rank[$a['kind'] ?? ''] ?? 3;
            $right = $rank[$b['kind'] ?? ''] ?? 3;

            return $left <=> $right ?: ((int) $a['id']) <=> ((int) $b['id']);
        });

        $this->info(sprintf('Done download jobs: %d%s', count($doneJobs), $dryRun ? ' (dry run)' : ''));

        foreach ($doneJobs as $job) {
            $files = $this->existingFiles($cleanup, $job);
            if ($files === []) {
                $missing++;
                $this->line(sprintf('Job #%d skipped: no files on disk', $job['id']));

                continue;
            }

            $userId = isset($job['user_id']) && $job['user_id'] !== null ? (int) $job['user_id'] : null;
            $providerName = is_string($job['provider'] ?? null) ? $job['provider'] : '';
            $url = (string) ($job['url'] ?? '');
            $cacheKey = $providerName.'|'.$url;

            if (! array_key_exists($cacheKey, $resolvedCache)) {
                if ($resolvedCache !== [] && $sleep > 0) {
                    sleep($sleep);
                }

                try {
                    $resolvedCache[$cacheKey] = $this->resolveTracks($providers, $settings, $providerName, $url, $userId);
                } catch (Throwable $exception) {
                    $resolvedCache[$cacheKey] = $exception;
                }
            }

            $resolved = $resolvedCache[$cacheKey];
            if ($resolved instanceof Throwable) {
                $resolveErrors++;
                $this->warn(sprintf('Job #%d resolve failed: %s', $job['id'], $resolved->getMessage()));

                continue;
            }

            $pairs = $matcher->match($resolved, $files);
            $written = 0;
            foreach ($pairs as $pair) {
                $track = $pair['track'];
                $externalId = (string) $track->id;
                if ($index->findPresent($userId, $providerName, $externalId) !== null) {
                    $already++;

                    continue;
                }

                if (! $dryRun) {
                    $index->upsert(
                        userId: $userId,
                        provider: $providerName,
                        externalId: $externalId,
                        filePath: $pair['path'],
                        title: $track->title,
                        artist: $track->artist?->name,
                        downloadJobId: (int) $job['id'],
                    );
                }
                $written++;
                $indexed++;
            }

            $this->line(sprintf(
                'Job #%d %s "%s": %d indexed, %d tracks resolved, %d files',
                $job['id'],
                (string) ($job['kind'] ?? ''),
                (string) ($job['title'] ?? $url),
                $written,
                count($resolved),
                count($files),
            ));
        }

        foreach ($playlists->listAll() as $playlist) {
            $userId = isset($playlist['user_id']) && $playlist['user_id'] !== null ? (int) $playlist['user_id'] : null;
            $providerName = (string) ($playlist['provider'] ?? '');
            foreach ($playlists->listTracks((int) $playlist['id']) as $track) {
                $status = (string) ($track['status'] ?? '');
                if (! in_array($status, [
                    PlaylistTrackStatus::Downloaded->value,
                    PlaylistTrackStatus::Existing->value,
                ], true)) {
                    continue;
                }

                $externalId = (string) ($track['external_id'] ?? '');
                $filePath = $track['file_path'] ?? null;
                if ($externalId === '' || ! is_string($filePath) || $filePath === '') {
                    continue;
                }

                $located = $cleanup->locateExistingFile($filePath);
                if ($located === null) {
                    continue;
                }

                if ($index->findPresent($userId, $providerName, $externalId) !== null) {
                    $already++;

                    continue;
                }

                if (! $dryRun) {
                    $index->upsert(
                        userId: $userId,
                        provider: $providerName,
                        externalId: $externalId,
                        filePath: $located,
                        title: is_string($track['title'] ?? null) ? $track['title'] : null,
                        artist: is_string($track['artist'] ?? null) ? $track['artist'] : null,
                        savedPlaylistTrackId: (int) $track['id'],
                    );
                }
                $indexed++;
                $this->line(sprintf('Playlist #%d track %s indexed', $playlist['id'], $externalId));
            }
        }

        $this->info(sprintf(
            '%s %d, already present %d, jobs without files %d, resolve errors %d.',
            $dryRun ? 'Dry run. Would index' : 'Indexed',
            $indexed,
            $already,
            $missing,
            $resolveErrors,
        ));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $job
     * @return list<string>
     */
    private function existingFiles(DownloadedFilesCleanup $cleanup, array $job): array
    {
        $files = [];
        foreach ($cleanup->pathsForJob($job) as $path) {
            $located = $cleanup->locateExistingFile($path);
            if ($located !== null && ! in_array($located, $files, true)) {
                $files[] = $located;
            }
        }

        return $files;
    }

    /**
     * @return list<Track>
     */
    private function resolveTracks(
        MusicProviderRegistry $providers,
        ProviderSettingsResolver $settings,
        string $providerName,
        string $url,
        ?int $userId,
    ): array {
        $provider = $providerName !== ''
            ? ($providers->findByName($providerName) ?? $providers->resolveForUrl($url))
            : $providers->resolveForUrl($url);

        if ($provider === null) {
            throw new \RuntimeException('No provider supports URL: '.$url);
        }

        if ($provider instanceof YoutubeMusicProvider) {
            $provider = $provider->usingCookies($settings->youtubeMusicCookiesPath($userId));
        }

        $tracks = [];
        foreach ($provider->resolve($url)->items as $item) {
            if ($item->item instanceof Track) {
                $tracks[] = $item->item;
            }
        }

        return $tracks;
    }
}
