<?php

declare(strict_types=1);

namespace App\Infrastructure\Providers\YoutubeMusic;

use App\Domain\Music\Contracts\MusicDownloader;
use App\Domain\Music\Contracts\MusicProvider;
use App\Domain\Music\Models\Album;
use App\Domain\Music\Models\Artist;
use App\Domain\Music\Models\Track;
use App\Domain\Music\ValueObjects\DownloadOptions;
use App\Domain\Music\ValueObjects\DownloadResult;
use App\Domain\Music\ValueObjects\ResolvedItem;
use App\Domain\Music\ValueObjects\ResolvedKind;
use App\Domain\Music\ValueObjects\ResolvedMusic;
use App\Infrastructure\Storage\LocalMusicStorage;
use RuntimeException;

final class YoutubeMusicProvider implements MusicProvider
{
    public function __construct(
        private MusicDownloader $downloader,
        private LocalMusicStorage $storage,
        private ?string $cookiesPath = null,
    ) {}

    public function name(): string
    {
        return 'youtube_music';
    }

    public function supports(string $url): bool
    {
        return (bool) preg_match(
            '#^https?://((music|www)\.)?youtube\.com/#i',
            $url,
        ) || (bool) preg_match('#^https?://youtu\.be/#i', $url);
    }

    public function resolve(string $url): ResolvedMusic
    {
        $metadata = $this->downloader->fetchMetadata($url, $this->cookiesPath);

        if ($this->isPlaylistMetadata($metadata)) {
            return $this->resolvePlaylist($url, $metadata);
        }

        return $this->resolveTrack($url, $metadata);
    }

    public function download(ResolvedItem $item, DownloadOptions $options): DownloadResult
    {
        if (! $item->item instanceof Track) {
            return DownloadResult::failed('Only individual tracks can be downloaded.');
        }

        $track = $item->item;
        $url = $this->buildTrackUrl($track);

        $directory = $this->storage->trackDirectory($track);
        $this->storage->ensureDirectory($directory);

        $filename = $this->storage->trackFilename($track, $options->format->extension());
        $basename = pathinfo($filename, PATHINFO_FILENAME);
        $outputTemplate = $directory.'/'.$basename.'.%(ext)s';

        return $this->downloader->download($url, $options, $outputTemplate);
    }

    private function resolveTrack(string $url, array $metadata): ResolvedMusic
    {
        $track = $this->parseTrack($metadata);

        return new ResolvedMusic(
            provider: $this->name(),
            kind: ResolvedKind::Track,
            title: $track->title,
            items: [
                new ResolvedItem(
                    kind: ResolvedKind::Track,
                    item: $track,
                    position: $track->index ?? 1,
                ),
            ],
            sourceUrl: $url,
        );
    }

    private function resolvePlaylist(string $url, array $metadata): ResolvedMusic
    {
        $entries = $metadata['entries'] ?? [];
        $items = [];
        $position = 1;

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $track = $this->parseTrack($entry, $position, $metadata);
            $items[] = new ResolvedItem(
                kind: ResolvedKind::Track,
                item: $track,
                position: $position,
            );
            $position++;
        }

        if ($items === []) {
            throw new RuntimeException('Playlist contains no downloadable entries.');
        }

        return new ResolvedMusic(
            provider: $this->name(),
            kind: $this->detectKind($url, $metadata),
            title: (string) ($metadata['title'] ?? 'Unknown Playlist'),
            items: $items,
            sourceUrl: $url,
        );
    }

    private function parseTrack(array $metadata, int $position = 1, ?array $parent = null): Track
    {
        $artistName = $metadata['artist']
            ?? $metadata['uploader']
            ?? $metadata['channel']
            ?? $parent['uploader']
            ?? $parent['artist']
            ?? 'Unknown Artist';

        $albumTitle = $metadata['album']
            ?? $parent['title']
            ?? 'Unknown Album';

        $index = $metadata['track']
            ?? $metadata['playlist_index']
            ?? $position;

        return new Track(
            title: (string) ($metadata['title'] ?? 'Unknown Title'),
            artist: new Artist((string) $artistName),
            album: new Album((string) $albumTitle, new Artist((string) $artistName)),
            index: max(1, (int) $index),
            id: $this->resolveTrackId($metadata),
            duration: isset($metadata['duration_string']) ? (string) $metadata['duration_string'] : null,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function resolveTrackId(array $metadata): ?string
    {
        $id = isset($metadata['id']) ? (string) $metadata['id'] : '';

        if ($id !== '') {
            return $id;
        }

        $url = isset($metadata['url']) ? (string) $metadata['url'] : '';

        if ($url !== '' && preg_match('#[?&]v=([a-zA-Z0-9_-]{11})#', $url, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function buildTrackUrl(Track $track): string
    {
        if ($track->id === null || $track->id === '') {
            throw new RuntimeException('Track is missing a YouTube id.');
        }

        return 'https://music.youtube.com/watch?v='.$track->id;
    }

    private function isPlaylistMetadata(array $metadata): bool
    {
        return ($metadata['_type'] ?? '') === 'playlist'
            || isset($metadata['entries']);
    }

    private function detectKind(string $url, array $metadata): ResolvedKind
    {
        if (preg_match('#music\.youtube\.com/browse/#i', $url)) {
            return ResolvedKind::Album;
        }

        if (($metadata['_type'] ?? '') === 'playlist' || isset($metadata['entries'])) {
            return ResolvedKind::Playlist;
        }

        return ResolvedKind::Track;
    }
}
