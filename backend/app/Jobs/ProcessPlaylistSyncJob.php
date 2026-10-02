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
use App\Domain\Music\Models\Album;
use App\Domain\Music\ValueObjects\ResolvedItem;
use App\Domain\Music\ValueObjects\ResolvedKind;
use App\Infrastructure\Providers\MusicProviderRegistry;
use App\Infrastructure\Providers\YoutubeMusic\YoutubeMusicProvider;
use App\Infrastructure\Storage\LocalMusicStorage;
use App\Application\PlaylistCover\PlaylistCoverGenerator;
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

    /** @var list<string> */
    private const AUDIO_EXTENSIONS = ['mp3', 'flac', 'm4a', 'mp4', 'aac', 'ogg', 'wav'];

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

            $options = $this->buildOptions($playlist, $provider->name(), $settings, $playlistDir, $userId);
            $this->relocateFlatTracks(
                $playlists,
                $downloadedTracks,
                $storage,
                $metadata,
                $playlistDir,
                $resolved->items,
                $lookup,
                $provider->name(),
                $options->deezerArl,
                $resolved->kind,
                count($resolved->items),
            );

            $pending = $playlists->listPendingTracks($this->savedPlaylistId);
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

                $result = $provider->download($resolvedItem, $options);

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
                    && $resolvedItem->item instanceof Track
                    && is_string($result->destinationPath)
                    && $result->destinationPath !== ''
                ) {
                    try {
                        $metadata->handle(
                            $result->destinationPath,
                            $resolvedItem->item,
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
                    $track = $resolvedItem->item instanceof Track ? $resolvedItem->item : null;
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

            try {
                app(PlaylistCoverGenerator::class)->generate($this->savedPlaylistId);
            } catch (Throwable $exception) {
                Log::warning('playlist cover generation failed', [
                    'playlist_id' => $this->savedPlaylistId,
                    'error' => $exception->getMessage(),
                ]);
            }

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
        string $playlistDirectory,
        ?int $userId = null,
    ): DownloadOptions {
        $formatValue = $playlist['default_format'] ?? $settings->defaultFormat()->value;
        $format = AudioFormat::tryFrom((string) $formatValue) ?? $settings->defaultFormat();

        return new DownloadOptions(
            format: $format,
            musicPath: $playlistDirectory,
            provider: $provider,
            cookiesPath: $settings->youtubeMusicCookiesPath($userId),
            deezerArl: $settings->deezerArl($userId),
            deezerMode: $settings->deezerMode(),
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

    /**
     * @param  list<ResolvedItem>  $items
     */
    private function relocateFlatTracks(
        SavedPlaylistRepository $playlists,
        DownloadedTrackRepository $downloadedTracks,
        LocalMusicStorage $storage,
        TrackMetadataApplicator $metadata,
        string $playlistDir,
        array $items,
        DownloadedTrackLookup $lookup,
        string $providerName,
        ?string $arl,
        ResolvedKind $kind,
        int $trackTotal,
    ): void {
        foreach ($playlists->listTracks($this->savedPlaylistId) as $row) {
            $filePath = $row['file_path'] ?? null;
            if (! is_string($filePath) || $filePath === '' || ! is_file($filePath)) {
                continue;
            }

            if (! $this->isDirectChild($filePath, $playlistDir)) {
                continue;
            }

            $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            if (! in_array($extension, self::AUDIO_EXTENSIONS, true)) {
                continue;
            }

            $resolvedItem = $this->findResolvedItem($items, (string) ($row['external_id'] ?? ''), $lookup);
            if ($resolvedItem === null || ! $resolvedItem->item instanceof Track) {
                continue;
            }

            $track = $resolvedItem->item;
            if ($track->album === null) {
                $track = new Track(
                    title: $track->title,
                    artist: $track->artist,
                    album: new Album('Unknown Album', $track->artist),
                    index: $track->index,
                    id: $track->id,
                    duration: $track->duration,
                    releaseYear: $track->releaseYear,
                );
            }

            $destinationDir = $storage->trackDirectory($track, $playlistDir);
            $storage->ensureDirectory($destinationDir);
            $destination = $destinationDir.'/'.$storage->trackFilename($track, $extension);

            if (is_file($destination) && realpath($destination) !== realpath($filePath)) {
                Log::warning('playlist flat track relocate skipped', [
                    'from' => $filePath,
                    'to' => $destination,
                    'reason' => 'destination exists',
                ]);

                continue;
            }

            if (! is_file($destination) && ! @rename($filePath, $destination)) {
                Log::warning('playlist flat track relocate failed', [
                    'from' => $filePath,
                    'to' => $destination,
                ]);

                continue;
            }

            $status = PlaylistTrackStatus::tryFrom((string) ($row['status'] ?? ''));
            if ($status !== null) {
                $playlists->updateTrackStatus((int) $row['id'], $status, filePath: $destination);
            }

            $downloadedTracks->replaceFilePath($filePath, $destination);

            $cover = $destinationDir.'/cover.jpg';
            if (
                $providerName === 'deezer'
                && (! is_file($cover) || filesize($cover) === 0)
            ) {
                try {
                    $metadata->handle(
                        $destination,
                        $track,
                        'deezer',
                        new MetadataEnrichContext(
                            arl: $arl,
                            kind: $kind,
                            trackTotal: $trackTotal,
                        ),
                    );
                } catch (Throwable $exception) {
                    Log::warning('playlist album cover write failed', [
                        'path' => $destination,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
        }
    }

    private function isDirectChild(string $path, string $directory): bool
    {
        $parent = rtrim(str_replace('\\', '/', dirname($path)), '/');
        $dir = rtrim(str_replace('\\', '/', $directory), '/');

        return $parent === $dir;
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
