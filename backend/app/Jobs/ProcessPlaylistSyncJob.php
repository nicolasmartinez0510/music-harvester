<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Settings\ProviderSettingsResolver;
use App\Domain\Music\Contracts\SavedPlaylistRepository;
use App\Domain\Music\Models\Track;
use App\Domain\Music\ValueObjects\AudioFormat;
use App\Domain\Music\ValueObjects\DownloadOptions;
use App\Domain\Music\ValueObjects\PlaylistSyncStatus;
use App\Domain\Music\ValueObjects\PlaylistTrackStatus;
use App\Domain\Music\ValueObjects\ResolvedItem;
use App\Domain\Music\ValueObjects\ResolvedKind;
use App\Infrastructure\Providers\MusicProviderRegistry;
use App\Infrastructure\Storage\LocalMusicStorage;
use App\Infrastructure\Storage\PlaylistM3uWriter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

class ProcessPlaylistSyncJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 7200;

    private const TRACK_DELAY_SECONDS = 2;

    public function __construct(
        public int $savedPlaylistId,
    ) {}

    public function handle(
        SavedPlaylistRepository $playlists,
        MusicProviderRegistry $providers,
        ProviderSettingsResolver $settings,
        LocalMusicStorage $storage,
        PlaylistM3uWriter $m3uWriter,
    ): void {
        $playlist = $playlists->find($this->savedPlaylistId);

        if ($playlist === null) {
            return;
        }

        // Status is usually already "running" (set when the job was enqueued).
        $playlists->updateSyncStatus($this->savedPlaylistId, PlaylistSyncStatus::Running);

        try {
            $url = (string) $playlist['url'];
            $providerName = is_string($playlist['provider'] ?? null) ? $playlist['provider'] : null;
            $provider = $providerName !== null
                ? ($providers->findByName($providerName) ?? $providers->resolveForUrl($url))
                : $providers->resolveForUrl($url);

            if ($provider === null) {
                throw new RuntimeException('No provider supports URL: '.$url);
            }

            $resolved = $provider->resolve($url);

            // Playlist may have been deleted while resolve was in flight.
            if ($playlists->find($this->savedPlaylistId) === null) {
                return;
            }

            if ($playlist['title'] === null || $playlist['title'] === '') {
                $playlists->update($this->savedPlaylistId, ['title' => $resolved->title]);
                $playlist = $playlists->find($this->savedPlaylistId) ?? $playlist;
                $playlist['title'] = $resolved->title;
            }

            $seenExternalIds = [];

            foreach ($resolved->items as $index => $item) {
                if (! $item->item instanceof Track) {
                    continue;
                }

                $track = $item->item;
                $externalId = $track->id;

                if ($externalId === null || $externalId === '') {
                    continue;
                }

                $seenExternalIds[] = $externalId;
                $position = $item->position > 0 ? $item->position : ($index + 1);

                $playlists->upsertTrack(
                    playlistId: $this->savedPlaylistId,
                    externalId: $externalId,
                    title: $track->title,
                    artist: $track->artist?->name,
                    position: $position,
                );
            }

            $playlists->markMissingTracksSkipped($this->savedPlaylistId, $seenExternalIds);

            $playlistDir = $storage->playlistDirectory(
                $this->savedPlaylistId,
                is_string($playlist['title'] ?? null) ? $playlist['title'] : null,
            );
            $storage->ensureDirectory($playlistDir);

            $pending = $playlists->listPendingTracks($this->savedPlaylistId);
            $options = $this->buildOptions($playlist, $provider->name(), $settings, $playlistDir);
            $completed = 0;

            foreach ($pending as $pendingTrack) {
                if ($playlists->find($this->savedPlaylistId) === null) {
                    return;
                }

                if ($completed > 0) {
                    sleep(self::TRACK_DELAY_SECONDS);
                }

                if ($playlists->find($this->savedPlaylistId) === null) {
                    return;
                }

                $resolvedItem = $this->findResolvedItem($resolved->items, (string) $pendingTrack['external_id']);

                if ($resolvedItem === null) {
                    $playlists->updateTrackStatus(
                        (int) $pendingTrack['id'],
                        PlaylistTrackStatus::Failed,
                        error: 'Track metadata missing after resolve.',
                    );

                    continue;
                }

                $downloadItem = $this->withPlaylistPosition(
                    $resolvedItem,
                    (int) ($pendingTrack['position'] ?? $resolvedItem->position),
                );

                $result = $provider->download($downloadItem, $options);

                if (! $result->success) {
                    $playlists->updateTrackStatus(
                        (int) $pendingTrack['id'],
                        PlaylistTrackStatus::Failed,
                        error: $result->error ?? 'Download failed.',
                    );

                    continue;
                }

                $playlists->updateTrackStatus(
                    (int) $pendingTrack['id'],
                    PlaylistTrackStatus::Downloaded,
                    filePath: $result->destinationPath,
                );
                $completed++;

                $this->regenerateM3u($playlists, $m3uWriter, $playlist);
            }

            $this->regenerateM3u($playlists, $m3uWriter, $playlist);

            $playlists->updateSyncStatus(
                $this->savedPlaylistId,
                PlaylistSyncStatus::Done,
                error: null,
                touchSyncedAt: true,
            );
        } catch (Throwable $exception) {
            $playlists->updateSyncStatus(
                $this->savedPlaylistId,
                PlaylistSyncStatus::Failed,
                error: $exception->getMessage(),
            );

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $playlist
     */
    private function buildOptions(
        array $playlist,
        string $provider,
        ProviderSettingsResolver $settings,
        string $targetDirectory,
    ): DownloadOptions {
        $formatValue = $playlist['default_format'] ?? config('music.default_format');
        $format = AudioFormat::tryFrom((string) $formatValue) ?? AudioFormat::Mp3_320;

        return new DownloadOptions(
            format: $format,
            musicPath: $settings->musicPath(),
            provider: $provider,
            cookiesPath: $settings->youtubeMusicCookiesPath(),
            deezerArl: $settings->deezerArl(),
            deezerMode: $settings->deezerMode(),
            targetDirectory: $targetDirectory,
        );
    }

    /**
     * @param  array<string, mixed>  $playlist
     */
    private function regenerateM3u(
        SavedPlaylistRepository $playlists,
        PlaylistM3uWriter $m3uWriter,
        array $playlist,
    ): void {
        $m3uWriter->write(
            [
                'id' => $this->savedPlaylistId,
                'title' => is_string($playlist['title'] ?? null) ? $playlist['title'] : null,
            ],
            $playlists->listTracks($this->savedPlaylistId),
        );
    }

    private function withPlaylistPosition(ResolvedItem $item, int $position): ResolvedItem
    {
        if (! $item->item instanceof Track) {
            return $item;
        }

        $track = $item->item;

        return new ResolvedItem(
            kind: ResolvedKind::Track,
            item: new Track(
                title: $track->title,
                artist: $track->artist,
                album: $track->album,
                index: max(1, $position),
                id: $track->id,
                duration: $track->duration,
            ),
            position: max(1, $position),
        );
    }

    /**
     * @param  list<ResolvedItem>  $items
     */
    private function findResolvedItem(array $items, string $externalId): ?ResolvedItem
    {
        foreach ($items as $item) {
            if ($item->item instanceof Track && $item->item->id === $externalId) {
                return $item;
            }
        }

        return null;
    }
}
