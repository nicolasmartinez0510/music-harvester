<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Auth\LibraryPathResolver;
use App\Application\IndexDownloadedTracks\DownloadedTrackLookup;
use App\Application\Settings\ProviderSettingsResolver;
use App\Domain\Music\Contracts\DownloadedTrackRepository;
use App\Domain\Music\Contracts\SavedPlaylistRepository;
use App\Domain\Music\Contracts\TrackMetadataApplicator;
use App\Domain\Music\Models\Track;
use App\Domain\Music\ValueObjects\AudioFormat;
use App\Domain\Music\ValueObjects\DownloadOptions;
use App\Domain\Music\ValueObjects\MetadataEnrichContext;
use App\Domain\Music\ValueObjects\PlaylistSyncStatus;
use App\Domain\Music\ValueObjects\PlaylistTrackStatus;
use App\Domain\Music\ValueObjects\ResolvedItem;
use App\Domain\Music\ValueObjects\ResolvedKind;
use App\Infrastructure\Providers\MusicProviderRegistry;
use App\Infrastructure\Providers\YoutubeMusic\YoutubeMusicProvider;
use App\Infrastructure\Storage\LocalMusicStorage;
use App\Infrastructure\Storage\PlaylistM3uWriter;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
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
        TrackMetadataApplicator $metadata,
        DownloadedTrackRepository $downloadedTracks,
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

            $userId = isset($playlist['user_id']) && $playlist['user_id'] !== null ? (int) $playlist['user_id'] : null;
            if ($provider instanceof YoutubeMusicProvider) {
                $provider = $provider->usingCookies($settings->youtubeMusicCookiesPath($userId));
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
            $lookup = new DownloadedTrackLookup();

            foreach ($resolved->items as $index => $item) {
                if (! $item->item instanceof Track) {
                    continue;
                }

                $track = $item->item;
                $externalId = $lookup->indexId($track);

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

            $owner = $userId !== null ? User::query()->find($userId) : null;
            $libraryRoot = $owner !== null
                ? app(LibraryPathResolver::class)->rootForPlaylist($owner)
                : null;

            $playlistDir = $storage->playlistDirectory(
                $this->savedPlaylistId,
                is_string($playlist['title'] ?? null) ? $playlist['title'] : null,
                $libraryRoot,
            );
            $storage->ensureDirectory($playlistDir);
            $this->relabelReusedTracks($playlists, $playlistDir);

            $pending = $playlists->listPendingTracks($this->savedPlaylistId);
            $options = $this->buildOptions($playlist, $provider->name(), $settings, $playlistDir, $userId);
            $downloadedCount = 0;

            foreach ($pending as $pendingTrack) {
                if ($playlists->find($this->savedPlaylistId) === null) {
                    return;
                }

                $resolvedItem = $this->findResolvedItem($resolved->items, (string) $pendingTrack['external_id'], $lookup);

                if ($resolvedItem === null) {
                    $playlists->updateTrackStatus(
                        (int) $pendingTrack['id'],
                        PlaylistTrackStatus::Failed,
                        error: 'Track metadata missing after resolve.',
                    );

                    continue;
                }

                $externalId = (string) $pendingTrack['external_id'];
                $existing = $resolvedItem->item instanceof Track
                    ? $lookup->present($downloadedTracks, $userId, $provider->name(), $resolvedItem->item)
                    : null;
                if ($existing !== null) {
                    $playlists->updateTrackStatus(
                        (int) $pendingTrack['id'],
                        PlaylistTrackStatus::Existing,
                        filePath: (string) $existing['file_path'],
                    );
                    $this->regenerateM3u($playlists, $m3uWriter, $playlist, $libraryRoot);

                    continue;
                }

                if ($downloadedCount > 0) {
                    sleep(self::TRACK_DELAY_SECONDS);
                }

                if ($playlists->find($this->savedPlaylistId) === null) {
                    return;
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

                if (
                    $provider->name() === 'deezer'
                    && $downloadItem->item instanceof Track
                    && is_string($result->destinationPath)
                    && $result->destinationPath !== ''
                ) {
                    try {
                        $metadata->handle(
                            $result->destinationPath,
                            $downloadItem->item,
                            'deezer',
                            new MetadataEnrichContext(
                                arl: $options->deezerArl,
                                kind: $resolved->kind,
                                trackTotal: count($resolved->items),
                            ),
                        );
                    } catch (Throwable $exception) {
                        Log::warning('audio metadata enrich failed', [
                            'path' => $result->destinationPath,
                            'error' => $exception->getMessage(),
                        ]);
                    }
                }

                if (is_string($result->destinationPath) && $result->destinationPath !== '') {
                    $track = $downloadItem->item instanceof Track ? $downloadItem->item : null;
                    $downloadedTracks->upsert(
                        userId: $userId,
                        provider: $provider->name(),
                        externalId: $externalId,
                        filePath: $result->destinationPath,
                        title: $track?->title ?? (is_string($pendingTrack['title'] ?? null) ? $pendingTrack['title'] : null),
                        artist: $track?->artist?->name ?? (is_string($pendingTrack['artist'] ?? null) ? $pendingTrack['artist'] : null),
                        downloadJobId: null,
                        savedPlaylistTrackId: (int) $pendingTrack['id'],
                        releaseYear: $track?->releaseYear,
                    );
                }

                $playlists->updateTrackStatus(
                    (int) $pendingTrack['id'],
                    PlaylistTrackStatus::Downloaded,
                    filePath: $result->destinationPath,
                );
                $downloadedCount++;

                $this->regenerateM3u($playlists, $m3uWriter, $playlist, $libraryRoot);
            }

            $this->regenerateM3u($playlists, $m3uWriter, $playlist, $libraryRoot);

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
        ?int $userId = null,
    ): DownloadOptions {
        $formatValue = $playlist['default_format'] ?? $settings->defaultFormat()->value;
        $format = AudioFormat::tryFrom((string) $formatValue) ?? $settings->defaultFormat();

        return new DownloadOptions(
            format: $format,
            musicPath: $settings->musicPath(),
            provider: $provider,
            cookiesPath: $settings->youtubeMusicCookiesPath($userId),
            deezerArl: $settings->deezerArl($userId),
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
        ?string $libraryRoot = null,
    ): void {
        $m3uWriter->write(
            [
                'id' => $this->savedPlaylistId,
                'title' => is_string($playlist['title'] ?? null) ? $playlist['title'] : null,
            ],
            $playlists->listTracks($this->savedPlaylistId),
            $libraryRoot,
        );
    }

    private function relabelReusedTracks(SavedPlaylistRepository $playlists, string $playlistDir): void
    {
        foreach ($playlists->listTracks($this->savedPlaylistId) as $track) {
            if (($track['status'] ?? '') !== PlaylistTrackStatus::Downloaded->value) {
                continue;
            }

            $filePath = $track['file_path'] ?? null;
            if (! is_string($filePath) || $filePath === '' || $this->pathIsInside($filePath, $playlistDir)) {
                continue;
            }

            $playlists->updateTrackStatus(
                (int) $track['id'],
                PlaylistTrackStatus::Existing,
                filePath: $filePath,
            );
        }
    }

    private function pathIsInside(string $path, string $directory): bool
    {
        $normalized = rtrim(str_replace('\\', '/', $path), '/');
        $dir = rtrim(str_replace('\\', '/', $directory), '/');

        return $dir !== '' && ($normalized === $dir || str_starts_with($normalized, $dir.'/'));
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
                releaseYear: $track->releaseYear,
            ),
            position: max(1, $position),
        );
    }

    /**
     * @param  list<ResolvedItem>  $items
     */
    private function findResolvedItem(array $items, string $externalId, DownloadedTrackLookup $lookup): ?ResolvedItem
    {
        foreach ($items as $item) {
            if ($item->item instanceof Track && $lookup->indexId($item->item) === $externalId) {
                return $item;
            }
        }

        return null;
    }
}
