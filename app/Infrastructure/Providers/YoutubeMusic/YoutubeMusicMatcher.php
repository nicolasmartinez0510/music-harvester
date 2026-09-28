<?php

declare(strict_types=1);

namespace App\Infrastructure\Providers\YoutubeMusic;

use App\Domain\Music\Contracts\MusicDownloader;
use App\Domain\Music\ValueObjects\DownloadOptions;
use App\Domain\Music\ValueObjects\DownloadResult;
use RuntimeException;

/**
 * Matches artist+title to a YouTube Music / YouTube video via yt-dlp search.
 */
final class YoutubeMusicMatcher
{
    public function __construct(
        private MusicDownloader $downloader,
    ) {}

    /**
     * @return array{id: string, title: string, artist: string, url: string}
     */
    public function match(string $artist, string $title, ?string $cookiesPath = null): array
    {
        $query = trim($artist.' '.$title);
        $searchUrl = 'ytsearch1:'.$query;

        $metadata = $this->downloader->fetchMetadata($searchUrl, $cookiesPath);
        $entry = $metadata;

        if (($metadata['_type'] ?? '') === 'playlist' && isset($metadata['entries'][0]) && is_array($metadata['entries'][0])) {
            $entry = $metadata['entries'][0];
        }

        $id = isset($entry['id']) ? (string) $entry['id'] : '';
        if ($id === '') {
            throw new RuntimeException('No YouTube match found for: '.$query);
        }

        return [
            'id' => $id,
            'title' => (string) ($entry['title'] ?? $title),
            'artist' => (string) ($entry['uploader'] ?? $entry['channel'] ?? $artist),
            'url' => 'https://music.youtube.com/watch?v='.$id,
        ];
    }

    public function downloadMatch(
        string $artist,
        string $title,
        DownloadOptions $options,
        string $outputTemplate,
    ): DownloadResult {
        $match = $this->match($artist, $title, $options->cookiesPath);

        return $this->downloader->download($match['url'], $options, $outputTemplate);
    }
}
