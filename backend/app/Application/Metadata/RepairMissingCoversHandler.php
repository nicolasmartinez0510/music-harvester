<?php

declare(strict_types=1);

namespace App\Application\Metadata;

use App\Application\Settings\ProviderSettingsResolver;
use App\Domain\Music\Contracts\AudioCoverProbe;
use App\Domain\Music\Contracts\DownloadedTrackRepository;
use App\Domain\Music\Contracts\TrackMetadataApplicator;
use App\Domain\Music\Models\Artist;
use App\Domain\Music\Models\Track;
use App\Domain\Music\ValueObjects\MetadataEnrichContext;
use App\Domain\Music\ValueObjects\ResolvedKind;
use App\Infrastructure\Storage\DownloadedFilesCleanup;
use Throwable;

final readonly class RepairMissingCoversHandler
{
    private const AUDIO_EXTENSIONS = ['mp3', 'flac', 'm4a', 'mp4', 'aac'];

    public function __construct(
        private DownloadedTrackRepository $tracks,
        private AudioCoverProbe $covers,
        private TrackMetadataApplicator $metadata,
        private ProviderSettingsResolver $settings,
        private DownloadedFilesCleanup $files,
    ) {}

    /**
     * @param  callable(string): void|null  $log
     */
    public function handle(bool $dryRun, int $sleepSeconds, ?callable $log = null): RepairMissingCoversResult
    {
        $scanned = 0;
        $alreadyCovered = 0;
        $missingFile = 0;
        $unsupported = 0;
        $repaired = 0;
        $stillMissing = 0;
        $failed = 0;
        $seen = [];
        $didRepair = false;

        foreach ($this->tracks->listByProvider('deezer') as $row) {
            $path = $this->locate($row);
            if ($path === null) {
                $missingFile++;
                $this->log($log, sprintf('missing file: %s', (string) ($row['file_path'] ?? '')));

                continue;
            }

            $key = realpath($path) ?: $path;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (! in_array($extension, self::AUDIO_EXTENSIONS, true)) {
                $unsupported++;

                continue;
            }

            $externalId = trim((string) ($row['external_id'] ?? ''));
            if ($externalId === '') {
                $failed++;
                $this->log($log, 'skipped '.$path.': missing Deezer track id');

                continue;
            }

            $scanned++;

            try {
                $hasCover = $this->covers->hasCover($path);
            } catch (Throwable $exception) {
                $failed++;
                $this->log($log, sprintf('probe failed %s: %s', $path, $exception->getMessage()));

                continue;
            }

            if ($hasCover) {
                $alreadyCovered++;

                continue;
            }

            if ($dryRun) {
                $stillMissing++;
                $this->log($log, sprintf('would repair %s (%s)', $path, $externalId));

                continue;
            }

            if ($didRepair && $sleepSeconds > 0) {
                sleep($sleepSeconds);
            }

            $userId = isset($row['user_id']) && $row['user_id'] !== null ? (int) $row['user_id'] : null;
            $title = is_string($row['title'] ?? null) && $row['title'] !== '' ? $row['title'] : $externalId;
            $artist = is_string($row['artist'] ?? null) && $row['artist'] !== '' ? $row['artist'] : null;

            try {
                $this->metadata->handle(
                    $path,
                    new Track(
                        title: $title,
                        artist: $artist !== null ? new Artist($artist) : null,
                        id: $externalId,
                    ),
                    'deezer',
                    new MetadataEnrichContext(
                        arl: $this->settings->deezerArl($userId),
                        kind: ResolvedKind::Track,
                    ),
                );
                $didRepair = true;
                $hasCover = $this->covers->hasCover($path);
            } catch (Throwable $exception) {
                $failed++;
                $this->log($log, sprintf('repair failed %s: %s', $path, $exception->getMessage()));

                continue;
            }

            if ($hasCover) {
                $repaired++;
                $this->log($log, 'repaired '.$path);
            } else {
                $stillMissing++;
                $this->log($log, 'still missing '.$path);
            }
        }

        return new RepairMissingCoversResult(
            scanned: $scanned,
            alreadyCovered: $alreadyCovered,
            missingFile: $missingFile,
            unsupported: $unsupported,
            repaired: $repaired,
            stillMissing: $stillMissing,
            failed: $failed,
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function locate(array $row): ?string
    {
        $path = $row['file_path'] ?? null;
        if (! is_string($path) || $path === '') {
            return null;
        }

        if (is_file($path)) {
            return $path;
        }

        return $this->files->locateExistingFile($path);
    }

    /**
     * @param  callable(string): void|null  $log
     */
    private function log(?callable $log, string $message): void
    {
        if ($log !== null) {
            $log($message);
        }
    }
}
