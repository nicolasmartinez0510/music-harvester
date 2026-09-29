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
use Throwable;

final class DeezerAudioMetadataEnricher implements TrackMetadataEnricher
{
    private const COVER_MAX_BYTES = 6_000_000;

    /** @var array<string, array<string, mixed>> */
    private array $albums = [];

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
            [$coverBytes, $coverMime] = $this->downloadCover($albumJson, $trackJson);
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
            $full = $embedded;
        }

        $this->albums[$id] = $full;

        return $full;
    }

    /**
     * @param  array<string, mixed>  $albumJson
     * @param  array<string, mixed>  $trackJson
     * @return array{0: ?string, 1: ?string}
     */
    private function downloadCover(array $albumJson, array $trackJson): array
    {
        $embedded = $trackJson['album'] ?? null;
        $url = $this->coverUrl($albumJson) ?? (is_array($embedded) ? $this->coverUrl($embedded) : null);
        if ($url === null) {
            return [null, null];
        }

        try {
            $response = Http::timeout(20)->get($url);
            if (! $response->successful()) {
                return [null, null];
            }

            $body = $response->body();
            if ($body === '' || strlen($body) > self::COVER_MAX_BYTES) {
                return [null, null];
            }

            return [$body, $this->imageMime($response->header('Content-Type'), $url)];
        } catch (Throwable) {
            return [null, null];
        }
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
