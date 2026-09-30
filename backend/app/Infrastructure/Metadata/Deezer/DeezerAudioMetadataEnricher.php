<?php

declare(strict_types=1);

namespace App\Infrastructure\Metadata\Deezer;

use App\Domain\Music\Contracts\TrackMetadataEnricher;
use App\Domain\Music\Models\Track;
use App\Domain\Music\ValueObjects\AudioFileMetadata;
use App\Domain\Music\ValueObjects\LyricsPayload;
use App\Domain\Music\ValueObjects\MetadataEnrichContext;
use App\Infrastructure\Providers\Deezer\DeezerApiClient;
use App\Infrastructure\Providers\Deezer\DeezerGwClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class DeezerAudioMetadataEnricher implements TrackMetadataEnricher
{
    private const COVER_MAX_BYTES = 6_000_000;

    private const COVER_ATTEMPTS = 3;

    /** @var array<string, array<string, mixed>> */
    private array $albums = [];

    /** @var array<string, array{0: string, 1: string}> */
    private array $covers = [];

    public function __construct(
        private DeezerApiClient $api,
        private DeezerGwClient $gw,
        private DeezerMetadataMapper $mapper,
    ) {}

    public function supports(string $provider): bool
    {
        return $provider === 'deezer';
    }

    public function enrich(Track $track, MetadataEnrichContext $context): AudioFileMetadata
    {
        $id = trim((string) $track->id);
        if ($id === '') {
            throw new \RuntimeException('Deezer track id is missing.');
        }

        $trackJson = $this->api->get('track/'.$id);
        $albumJson = $this->album($trackJson);

        $lyrics = null;
        if ($context->embedLyrics && is_string($context->arl) && $context->arl !== '') {
            $lyrics = $this->gw->trackLyrics($context->arl, $id);
        }

        $coverBytes = null;
        $coverMime = null;
        if ($context->embedCover) {
            [$coverBytes, $coverMime] = $this->downloadCover($albumJson, $trackJson, $id);
        }

        return $this->mapper->map($track, $trackJson, $albumJson, $lyrics instanceof LyricsPayload ? $lyrics : null, $coverBytes, $coverMime, $context);
    }

    /**
     * @param  array<string, mixed>  $trackJson
     * @return array<string, mixed>
     */
    private function album(array $trackJson): array
    {
        $embedded = $trackJson['album'] ?? null;
        $embedded = is_array($embedded) ? $embedded : [];
        $id = isset($embedded['id']) ? trim((string) $embedded['id']) : '';
        if ($id === '') {
            return $embedded;
        }

        if (isset($this->albums[$id])) {
            return $this->albums[$id];
        }

        try {
            $full = $this->api->get('album/'.$id);
        } catch (Throwable) {
            return $embedded;
        }

        $this->albums[$id] = $full;

        return $full;
    }

    /**
     * @param  array<string, mixed>  $albumJson
     * @param  array<string, mixed>  $trackJson
     * @return array{0: ?string, 1: ?string}
     */
    private function downloadCover(array $albumJson, array $trackJson, string $trackId): array
    {
        $albumId = $this->albumId($albumJson, $trackJson);
        if ($albumId !== '' && isset($this->covers[$albumId])) {
            return $this->covers[$albumId];
        }

        $embedded = $trackJson['album'] ?? null;
        $url = $this->coverUrl($albumJson) ?? (is_array($embedded) ? $this->coverUrl($embedded) : null);
        if ($url === null) {
            $this->warnCover($trackId, $albumId, null, null, 'missing_url');

            return [null, null];
        }

        $lastStatus = null;
        $reason = 'http';

        for ($attempt = 1; $attempt <= self::COVER_ATTEMPTS; $attempt++) {
            try {
                $response = Http::timeout(20)->get($url);
            } catch (Throwable) {
                $reason = 'exception';
                $lastStatus = null;
                $this->backoff($attempt);

                continue;
            }

            if (! $response->successful()) {
                $reason = 'http';
                $lastStatus = $response->status();
                $this->backoff($attempt);

                continue;
            }

            $body = $response->body();
            $status = $response->status();
            if ($body === '') {
                $reason = 'empty';
                $lastStatus = $status;
                $this->backoff($attempt);

                continue;
            }

            if (strlen($body) > self::COVER_MAX_BYTES) {
                $this->warnCover($trackId, $albumId, $url, $status, 'too_large');

                return [null, null];
            }

            if (! $this->looksLikeImage($body)) {
                $this->warnCover($trackId, $albumId, $url, $status, 'not_image');

                return [null, null];
            }

            $result = [$body, $this->imageMime($response->header('Content-Type'), $url)];
            if ($albumId !== '') {
                $this->covers[$albumId] = $result;
            }

            return $result;
        }

        $this->warnCover($trackId, $albumId, $url, $lastStatus, $reason);

        return [null, null];
    }

    /**
     * @param  array<string, mixed>  $albumJson
     * @param  array<string, mixed>  $trackJson
     */
    private function albumId(array $albumJson, array $trackJson): string
    {
        $id = isset($albumJson['id']) ? trim((string) $albumJson['id']) : '';
        if ($id !== '') {
            return $id;
        }

        $embedded = $trackJson['album'] ?? null;
        if (is_array($embedded) && isset($embedded['id'])) {
            return trim((string) $embedded['id']);
        }

        return '';
    }

    private function backoff(int $attempt): void
    {
        if ($attempt < self::COVER_ATTEMPTS) {
            usleep(100_000 * $attempt);
        }
    }

    private function warnCover(string $trackId, string $albumId, ?string $url, ?int $status, string $reason): void
    {
        Log::warning('deezer cover fetch failed', [
            'track_id' => $trackId,
            'album_id' => $albumId !== '' ? $albumId : null,
            'url' => $url,
            'status' => $status,
            'reason' => $reason,
        ]);
    }

    private function looksLikeImage(string $body): bool
    {
        return str_starts_with($body, "\xFF\xD8")
            || str_starts_with($body, "\x89PNG\r\n\x1a\n");
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function coverUrl(array $source): ?string
    {
        foreach (['cover_xl', 'cover_big', 'cover_medium'] as $key) {
            $url = $source[$key] ?? null;
            if (is_string($url) && str_starts_with($url, 'http')) {
                return $url;
            }
        }

        return null;
    }

    private function imageMime(mixed $header, string $url): string
    {
        if (is_string($header) && str_starts_with($header, 'image/')) {
            return trim(explode(';', $header)[0]);
        }

        return str_ends_with(strtolower(parse_url($url, PHP_URL_PATH) ?: ''), '.png')
            ? 'image/png'
            : 'image/jpeg';
    }
}
