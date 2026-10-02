<?php

declare(strict_types=1);

namespace App\Infrastructure\Providers\Deezer;

use App\Domain\Music\Contracts\ArtistPortraitLookup;
use Illuminate\Support\Facades\Log;
use Throwable;

final class DeezerArtistPortraitLookup implements ArtistPortraitLookup
{
    public function __construct(
        private DeezerApiClient $api,
    ) {}

    public function portraitUrl(string $artistName): ?string
    {
        $name = trim($artistName);
        if ($name === '') {
            return null;
        }

        try {
            $payload = $this->api->get('search/artist', [
                'q' => $name,
                'limit' => 5,
            ]);
        } catch (Throwable $exception) {
            Log::warning('artist portrait lookup failed', [
                'artist' => $name,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        $rows = $payload['data'] ?? [];
        if (! is_array($rows)) {
            return null;
        }

        $needle = mb_strtolower($name);
        $fallback = null;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $url = $this->pictureUrl($row);
            if ($url === null) {
                continue;
            }

            $fallback ??= $url;
            $candidate = isset($row['name']) && is_string($row['name']) ? mb_strtolower(trim($row['name'])) : '';
            if ($candidate !== '' && $candidate === $needle) {
                return $url;
            }
        }

        return $fallback;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function pictureUrl(array $row): ?string
    {
        foreach (['picture_xl', 'picture_big', 'picture_medium'] as $key) {
            if (isset($row[$key]) && is_string($row[$key]) && $row[$key] !== '') {
                return $row[$key];
            }
        }

        return null;
    }
}
