<?php

declare(strict_types=1);

namespace App\Infrastructure\Providers\Deezer;

use App\Application\Settings\ProviderSettingsResolver;
use App\Domain\Music\Contracts\CatalogSource;
use App\Domain\Music\Contracts\MusicProvider;
use App\Domain\Music\Contracts\UserLibrarySource;
use App\Domain\Music\Models\Album;
use App\Domain\Music\Models\Artist;
use App\Domain\Music\Models\Track;
use App\Domain\Music\ValueObjects\AudioFormat;
use App\Domain\Music\ValueObjects\CatalogAlbum;
use App\Domain\Music\ValueObjects\CatalogArtist;
use App\Domain\Music\ValueObjects\CatalogHit;
use App\Domain\Music\ValueObjects\CatalogPlaylist;
use App\Domain\Music\ValueObjects\CatalogType;
use App\Domain\Music\ValueObjects\DownloadOptions;
use App\Domain\Music\ValueObjects\DownloadResult;
use App\Domain\Music\ValueObjects\ResolvedItem;
use App\Domain\Music\ValueObjects\ResolvedKind;
use App\Domain\Music\ValueObjects\ResolvedMusic;
use App\Infrastructure\Providers\YoutubeMusic\YoutubeMusicMatcher;
use App\Infrastructure\Storage\LocalMusicStorage;
use RuntimeException;

final class DeezerProvider implements MusicProvider, CatalogSource, UserLibrarySource
{
    public function __construct(
        private DeezerApiClient $api,
        private DeezerGwClient $gw,
        private StreamripDeezerDownloader $nativeDownloader,
        private YoutubeMusicMatcher $youtubeMatcher,
        private LocalMusicStorage $storage,
        private ProviderSettingsResolver $settings,
    ) {}

    public function name(): string
    {
        return 'deezer';
    }

    public function supports(string $url): bool
    {
        return (bool) preg_match(
            '#^https?://(www\.)?deezer\.com(/[a-z]{2})?/(track|album|playlist)/#i',
            $url,
        ) || (bool) preg_match('#^https?://link\.deezer\.com/#i', $url);
    }

    public function resolve(string $url): ResolvedMusic
    {
        $canonical = $this->canonicalizeUrl($url);
        $parsed = $this->parseUrl($canonical);

        return match ($parsed['kind']) {
            'track' => $this->resolveTrack($parsed['id'], $canonical),
            'album' => $this->resolveAlbum($parsed['id'], $canonical),
            'playlist' => $this->resolvePlaylist($parsed['id'], $canonical),
            default => throw new RuntimeException('Unsupported Deezer URL: '.$url),
        };
    }

    public function download(ResolvedItem $item, DownloadOptions $options): DownloadResult
    {
        if (! $item->item instanceof Track) {
            return DownloadResult::failed('Only individual tracks can be downloaded.');
        }

        $track = $item->item;

        if ($track->id === null || $track->id === '') {
            return DownloadResult::failed('Track is missing a Deezer id.');
        }

        $directory = $options->targetDirectory ?? $this->storage->trackDirectory($track, $options->musicPath);
        $this->storage->ensureDirectory($directory);
        $playlistLayout = $options->targetDirectory !== null;

        $mode = $this->resolveDownloadMode($options);

        if ($mode === 'hybrid') {
            return $this->downloadHybrid($track, $options, $directory, $playlistLayout);
        }

        return $this->downloadNative($track, $options, $directory, $playlistLayout);
    }

    public function search(string $query, CatalogType $type, int $limit = 25, int $index = 0): array
    {
        $limit = max(1, min(100, $limit));
        $index = max(0, $index);

        if ($type === CatalogType::All) {
            $hits = [];
            foreach ([CatalogType::Track, CatalogType::Album, CatalogType::Artist, CatalogType::Playlist] as $subType) {
                $hits = array_merge($hits, $this->search($query, $subType, min(10, $limit), $index));
            }

            return array_slice($hits, 0, $limit);
        }

        $path = match ($type) {
            CatalogType::Track => 'search/track',
            CatalogType::Album => 'search/album',
            CatalogType::Artist => 'search/artist',
            CatalogType::Playlist => 'search/playlist',
            CatalogType::All => 'search',
        };

        $payload = $this->api->get($path, ['q' => $query, 'limit' => $limit, 'index' => $index]);
        $data = $payload['data'] ?? [];
        $hits = [];

        if (! is_array($data)) {
            return [];
        }

        foreach ($data as $row) {
            if (! is_array($row)) {
                continue;
            }
            $hits[] = $this->mapHit($row, $type);
        }

        return $hits;
    }

    public function getArtist(string $id): CatalogArtist
    {
        $artist = $this->api->get('artist/'.$id);
        $top = $this->api->get('artist/'.$id.'/top', ['limit' => 25]);
        $albums = $this->api->get('artist/'.$id.'/albums', ['limit' => 50]);

        $topHits = [];
        foreach (($top['data'] ?? []) as $row) {
            if (is_array($row)) {
                $topHits[] = $this->mapHit($row, CatalogType::Track);
            }
        }

        $albumHits = [];
        foreach (($albums['data'] ?? []) as $row) {
            if (is_array($row)) {
                $albumHits[] = $this->mapHit($row, CatalogType::Album);
            }
        }

        return new CatalogArtist(
            id: (string) ($artist['id'] ?? $id),
            name: (string) ($artist['name'] ?? 'Unknown Artist'),
            coverUrl: $this->artistCoverUrl($artist),
            canonicalUrl: $this->canonical('artist', (string) ($artist['id'] ?? $id)),
            nbFans: (int) ($artist['nb_fan'] ?? 0),
            topTracks: $topHits,
            albums: $albumHits,
        );
    }

    public function getAlbum(string $id): CatalogAlbum
    {
        $album = $this->api->get('album/'.$id);
        $tracks = $this->api->getAllData('album/'.$id.'/tracks');

        $trackHits = [];
        foreach ($tracks as $row) {
            $trackHits[] = $this->mapHit($row, CatalogType::Track);
        }

        $artistName = null;
        if (isset($album['artist']) && is_array($album['artist'])) {
            $artistName = isset($album['artist']['name']) ? (string) $album['artist']['name'] : null;
        }

        return new CatalogAlbum(
            id: (string) ($album['id'] ?? $id),
            title: (string) ($album['title'] ?? 'Unknown Album'),
            artistName: $artistName,
            coverUrl: isset($album['cover_medium']) ? (string) $album['cover_medium'] : null,
            canonicalUrl: $this->canonical('album', (string) ($album['id'] ?? $id)),
            nbTracks: (int) ($album['nb_tracks'] ?? count($trackHits)),
            tracks: $trackHits,
        );
    }

    public function isLibraryAvailable(): bool
    {
        return $this->settings->isArlConfigured();
    }

    public function favoriteArtists(int $limit = 50, int $index = 0): array
    {
        return $this->mapLibraryRows(
            $this->gw->favoriteArtists($this->requireArl(), $limit, $index),
            CatalogType::Artist,
        );
    }

    public function favoriteAlbums(int $limit = 50, int $index = 0): array
    {
        return $this->mapLibraryRows(
            $this->gw->favoriteAlbums($this->requireArl(), $limit, $index),
            CatalogType::Album,
        );
    }

    public function lovedTracks(int $limit = 50, int $index = 0): array
    {
        return $this->mapLibraryRows(
            $this->gw->lovedTracks($this->requireArl(), $limit, $index),
            CatalogType::Track,
        );
    }

    public function playlists(int $limit = 50, int $index = 0): array
    {
        return $this->mapLibraryRows(
            $this->gw->playlists($this->requireArl(), $limit, $index),
            CatalogType::Playlist,
        );
    }

    public function getPlaylist(string $id): CatalogPlaylist
    {
        $playlist = $this->api->get('playlist/'.$id);
        $tracks = $this->api->getAllData('playlist/'.$id.'/tracks');

        $trackHits = [];
        foreach ($tracks as $row) {
            $trackHits[] = $this->mapHit($row, CatalogType::Track);
        }

        $creator = null;
        if (isset($playlist['creator']) && is_array($playlist['creator'])) {
            $creator = isset($playlist['creator']['name']) ? (string) $playlist['creator']['name'] : null;
        }

        return new CatalogPlaylist(
            id: (string) ($playlist['id'] ?? $id),
            title: (string) ($playlist['title'] ?? 'Unknown Playlist'),
            creatorName: $creator,
            coverUrl: isset($playlist['picture_medium']) ? (string) $playlist['picture_medium'] : null,
            canonicalUrl: $this->canonical('playlist', (string) ($playlist['id'] ?? $id)),
            nbTracks: (int) ($playlist['nb_tracks'] ?? count($trackHits)),
            tracks: $trackHits,
        );
    }

    private function resolveTrack(string $id, string $sourceUrl): ResolvedMusic
    {
        $data = $this->api->get('track/'.$id);
        $track = $this->mapTrack($data);

        return new ResolvedMusic(
            provider: $this->name(),
            kind: ResolvedKind::Track,
            title: $track->title,
            items: [
                new ResolvedItem(kind: ResolvedKind::Track, item: $track, position: 1),
            ],
            sourceUrl: $sourceUrl,
        );
    }

    private function resolveAlbum(string $id, string $sourceUrl): ResolvedMusic
    {
        $album = $this->api->get('album/'.$id);
        $rows = $this->api->getAllData('album/'.$id.'/tracks');
        $albumTitle = (string) ($album['title'] ?? 'Unknown Album');
        $artistName = 'Unknown Artist';
        if (isset($album['artist']) && is_array($album['artist'])) {
            $artistName = (string) ($album['artist']['name'] ?? $artistName);
        }

        $items = [];
        $position = 1;
        foreach ($rows as $row) {
            $track = $this->mapTrack($row, $position, $albumTitle, $artistName);
            $items[] = new ResolvedItem(kind: ResolvedKind::Track, item: $track, position: $position);
            $position++;
        }

        if ($items === []) {
            throw new RuntimeException('Album contains no downloadable tracks.');
        }

        return new ResolvedMusic(
            provider: $this->name(),
            kind: ResolvedKind::Album,
            title: $albumTitle,
            items: $items,
            sourceUrl: $sourceUrl,
        );
    }

    private function resolvePlaylist(string $id, string $sourceUrl): ResolvedMusic
    {
        $playlist = $this->api->get('playlist/'.$id);
        $rows = $this->api->getAllData('playlist/'.$id.'/tracks');

        $items = [];
        $position = 1;
        foreach ($rows as $row) {
            $track = $this->mapTrack($row, $position);
            $items[] = new ResolvedItem(kind: ResolvedKind::Track, item: $track, position: $position);
            $position++;
        }

        if ($items === []) {
            throw new RuntimeException('Playlist contains no downloadable tracks.');
        }

        return new ResolvedMusic(
            provider: $this->name(),
            kind: ResolvedKind::Playlist,
            title: (string) ($playlist['title'] ?? 'Unknown Playlist'),
            items: $items,
            sourceUrl: $sourceUrl,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function mapTrack(
        array $data,
        int $position = 1,
        ?string $albumFallback = null,
        ?string $artistFallback = null,
    ): Track {
        $artistName = $artistFallback ?? 'Unknown Artist';
        if (isset($data['artist']) && is_array($data['artist'])) {
            $artistName = (string) ($data['artist']['name'] ?? $artistName);
        }

        $albumTitle = $albumFallback ?? 'Unknown Album';
        if (isset($data['album']) && is_array($data['album'])) {
            $albumTitle = (string) ($data['album']['title'] ?? $albumTitle);
        }

        $index = isset($data['track_position']) ? (int) $data['track_position'] : $position;
        $duration = isset($data['duration']) ? $this->formatDuration((int) $data['duration']) : null;

        return new Track(
            title: (string) ($data['title'] ?? 'Unknown Title'),
            artist: new Artist($artistName),
            album: new Album($albumTitle, new Artist($artistName)),
            index: max(1, $index),
            id: isset($data['id']) ? (string) $data['id'] : null,
            duration: $duration,
        );
    }

    private function requireArl(): string
    {
        $arl = $this->settings->deezerArl();
        if ($arl === null || ! $this->settings->isArlConfigured($arl)) {
            throw new RuntimeException(
                'Deezer ARL is not configured. Set provider_deezer_arl in settings to access Mi Colección.',
            );
        }

        return $arl;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<CatalogHit>
     */
    private function mapLibraryRows(array $rows, CatalogType $type): array
    {
        $hits = [];
        foreach ($rows as $row) {
            $hits[] = $this->mapHit($row, $type);
        }

        return $hits;
    }

    /**
     * @param  array<string, mixed>  $artist
     */
    private function artistCoverUrl(array $artist): ?string
    {
        foreach (['picture_xl', 'picture_big', 'picture_medium'] as $key) {
            if (isset($artist[$key]) && is_string($artist[$key]) && $artist[$key] !== '') {
                return $artist[$key];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function mediaCoverUrl(array $row): ?string
    {
        foreach (['cover_medium', 'picture_medium', 'album'] as $key) {
            if ($key === 'album' && isset($row['album']) && is_array($row['album'])) {
                return isset($row['album']['cover_medium']) ? (string) $row['album']['cover_medium'] : null;
            }
            if (isset($row[$key]) && is_string($row[$key]) && $row[$key] !== '') {
                return $row[$key];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function mapHit(array $row, CatalogType $fallbackType): CatalogHit
    {
        $typeValue = isset($row['type']) ? (string) $row['type'] : $fallbackType->value;
        $type = CatalogType::tryFrom($typeValue) ?? $fallbackType;
        $id = (string) ($row['id'] ?? '');

        $title = match ($type) {
            CatalogType::Artist => (string) ($row['name'] ?? 'Unknown'),
            default => (string) ($row['title'] ?? $row['name'] ?? 'Unknown'),
        };

        $subtitle = null;
        if (isset($row['artist']) && is_array($row['artist'])) {
            $subtitle = isset($row['artist']['name']) ? (string) $row['artist']['name'] : null;
        } elseif (isset($row['user']) && is_array($row['user'])) {
            $subtitle = isset($row['user']['name']) ? (string) $row['user']['name'] : null;
        }

        $cover = $type === CatalogType::Artist
            ? $this->artistCoverUrl($row)
            : $this->mediaCoverUrl($row);

        $kindPath = match ($type) {
            CatalogType::Track => 'track',
            CatalogType::Album => 'album',
            CatalogType::Artist => 'artist',
            CatalogType::Playlist => 'playlist',
            CatalogType::All => 'track',
        };

        return new CatalogHit(
            type: $type,
            id: $id,
            title: $title,
            subtitle: $subtitle,
            coverUrl: $cover,
            canonicalUrl: $id !== '' ? $this->canonical($kindPath, $id) : null,
            nbTracks: isset($row['nb_tracks']) ? (int) $row['nb_tracks'] : null,
            releaseDate: isset($row['release_date']) && is_string($row['release_date']) && $row['release_date'] !== ''
                ? $row['release_date']
                : null,
            fans: isset($row['fans']) ? (int) $row['fans'] : (isset($row['rank']) ? (int) $row['rank'] : null),
            recordType: isset($row['record_type']) && is_string($row['record_type']) && $row['record_type'] !== ''
                ? $row['record_type']
                : null,
        );
    }

    private function downloadNative(
        Track $track,
        DownloadOptions $options,
        string $directory,
        bool $playlistLayout = false,
    ): DownloadResult {
        $arl = $options->deezerArl;
        if ($arl === null || $arl === '') {
            return DownloadResult::failed(
                'Deezer ARL is not configured. Set provider_deezer_arl in settings or switch to hybrid mode.',
            );
        }

        $deezerUrl = $this->canonical('track', $track->id ?? '');
        $format = $options->format === AudioFormat::M4a ? AudioFormat::Mp3_320 : $options->format;

        try {
            $downloadedPath = $this->nativeDownloader->download(
                $deezerUrl,
                $arl,
                $format === AudioFormat::Flac ? AudioFormat::Flac : AudioFormat::Mp3_320,
                $directory,
            );
        } catch (RuntimeException $exception) {
            return DownloadResult::failed($exception->getMessage());
        }

        $ext = $format === AudioFormat::Flac ? 'flac' : 'mp3';
        $targetFilename = $playlistLayout
            ? $this->storage->playlistTrackFilename($track, $ext)
            : $this->storage->trackFilename($track, $ext);
        $targetPath = $directory.'/'.$targetFilename;

        if (realpath($downloadedPath) !== realpath($targetPath)) {
            $actualExt = pathinfo($downloadedPath, PATHINFO_EXTENSION) ?: $ext;
            $targetPath = $directory.'/'.($playlistLayout
                ? $this->storage->playlistTrackFilename($track, $actualExt)
                : $this->storage->trackFilename($track, $actualExt));
            if (! @rename($downloadedPath, $targetPath)) {
                if (! @copy($downloadedPath, $targetPath)) {
                    return DownloadResult::ok($downloadedPath);
                }
                @unlink($downloadedPath);
            }
        }

        return DownloadResult::ok($targetPath);
    }

    private function downloadHybrid(
        Track $track,
        DownloadOptions $options,
        string $directory,
        bool $playlistLayout = false,
    ): DownloadResult {
        $artist = $track->artist?->name ?? 'Unknown Artist';
        $filename = $playlistLayout
            ? $this->storage->playlistTrackFilename($track, $options->format->extension())
            : $this->storage->trackFilename($track, $options->format->extension());
        $basename = pathinfo($filename, PATHINFO_FILENAME);
        $outputTemplate = $directory.'/'.$basename.'.%(ext)s';

        return $this->youtubeMatcher->downloadMatch($artist, $track->title, $options, $outputTemplate);
    }

    private function resolveDownloadMode(DownloadOptions $options): string
    {
        if ($options->deezerMode === 'hybrid') {
            return 'hybrid';
        }

        if ($options->deezerArl === null || $options->deezerArl === '') {
            return 'hybrid';
        }

        return 'native';
    }

    private function canonicalizeUrl(string $url): string
    {
        if (preg_match('#^https?://link\.deezer\.com/#i', $url)) {
            $headers = @get_headers($url, true);
            if (is_array($headers)) {
                $location = $headers['Location'] ?? null;
                if (is_array($location)) {
                    $location = end($location);
                }
                if (is_string($location) && $location !== '') {
                    return $location;
                }
            }
        }

        return $url;
    }

    /**
     * @return array{kind: string, id: string}
     */
    private function parseUrl(string $url): array
    {
        if (! preg_match('#deezer\.com(?:/[a-z]{2})?/(track|album|playlist)/(\d+)#i', $url, $matches)) {
            throw new RuntimeException('Cannot parse Deezer URL: '.$url);
        }

        return [
            'kind' => strtolower($matches[1]),
            'id' => $matches[2],
        ];
    }

    private function canonical(string $kind, string $id): string
    {
        return sprintf('https://www.deezer.com/%s/%s', $kind, $id);
    }

    private function formatDuration(int $seconds): string
    {
        $minutes = intdiv($seconds, 60);
        $remain = $seconds % 60;

        return sprintf('%d:%02d', $minutes, $remain);
    }
}
