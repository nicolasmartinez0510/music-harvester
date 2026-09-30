<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Application\Settings\ProviderSettingsResolver;
use App\Domain\Music\Contracts\DownloadedTrackRepository;
use App\Domain\Music\ValueObjects\AudioFormat;
use App\Domain\Music\ValueObjects\DownloadStatus;
use App\Infrastructure\Storage\DownloadedFilesCleanup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin array<string, mixed>
 */
final class DownloadJobResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $job = is_array($this->resource) ? $this->resource : (array) $this->resource;
        $cleanup = app(DownloadedFilesCleanup::class);
        $job = $this->revealExistingTrack($job, $cleanup);
        $filesPresent = $cleanup->filesPresent($job);

        return [
            'id' => (int) $job['id'],
            'provider' => (string) $job['provider'],
            'url' => (string) $job['url'],
            'kind' => (string) $job['kind'],
            'title' => isset($job['title']) && is_string($job['title']) ? $job['title'] : null,
            'artist' => isset($job['artist']) && is_string($job['artist']) ? $job['artist'] : null,
            'status' => (string) $job['status'],
            'progress' => (int) $job['progress'],
            'error' => $job['error'],
            'destination_path' => $job['destination_path'],
            'files_present' => $filesPresent,
            'format' => $this->resolveFormat($job),
            'download_destination' => (string) ($job['download_destination'] ?? 'server'),
            'can_download_artifact' => ($job['download_destination'] ?? 'server') === 'direct'
                && ($job['status'] ?? '') === DownloadStatus::Done->value
                && $filesPresent,
            'created_at' => $job['created_at'],
            'updated_at' => $job['updated_at'],
        ];
    }

    /**
     * Jobs finished before reuse recorded a path stay "done" with empty paths.
     * If the track is still in the index, show it as already present.
     *
     * @param  array<string, mixed>  $job
     * @return array<string, mixed>
     */
    private function revealExistingTrack(array $job, DownloadedFilesCleanup $cleanup): array
    {
        if (($job['status'] ?? '') !== DownloadStatus::Done->value || ($job['kind'] ?? '') !== 'track') {
            return $job;
        }

        if ($cleanup->pathsForJob($job) !== []) {
            return $job;
        }

        $externalId = $this->trackExternalId((string) ($job['url'] ?? ''));
        $provider = is_string($job['provider'] ?? null) ? $job['provider'] : '';
        if ($externalId === null || $provider === '') {
            return $job;
        }

        $userId = isset($job['user_id']) && $job['user_id'] !== null ? (int) $job['user_id'] : null;
        $recorded = app(DownloadedTrackRepository::class)->findRecorded($userId, $provider, $externalId);
        $path = is_array($recorded) && is_string($recorded['file_path'] ?? null) ? $recorded['file_path'] : '';
        if ($path === '' || ! is_file($path)) {
            return $job;
        }

        $job['status'] = DownloadStatus::Existing->value;
        $job['destination_path'] = $path;
        $job['downloaded_paths'] = json_encode([$path]);

        return $job;
    }

    private function trackExternalId(string $url): ?string
    {
        if (preg_match('#deezer\.com(?:/[a-z]{2})?/track/(\d+)#i', $url, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('#[?&]v=([\w-]{6,})#', $url, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('#youtu\.be/([\w-]{6,})#', $url, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $job
     */
    private function resolveFormat(array $job): string
    {
        $optionsJson = json_decode((string) ($job['options_json'] ?? '{}'), true);
        $formatValue = is_array($optionsJson) ? ($optionsJson['format'] ?? null) : null;
        $format = AudioFormat::tryFrom((string) ($formatValue ?? app(ProviderSettingsResolver::class)->defaultFormat()->value));

        return ($format ?? AudioFormat::Mp3_320)->value;
    }
}
