<?php

declare(strict_types=1);

namespace App\Infrastructure\Biography;

use App\Domain\Music\Contracts\ArtistBiographyLookup;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Resolves short artist bios from Wikipedia (es → en), cached to respect rate limits.
 */
final class WikipediaArtistBiographyLookup implements ArtistBiographyLookup
{
    private const USER_AGENT = 'MusicHarvester/1.0 (https://github.com/nicolasmartinez0510/music-harvester)';

    private const HIT_TTL_SECONDS = 60 * 60 * 24 * 7;

    private const MISS_TTL_SECONDS = 60 * 60 * 24;

    private const MAX_LENGTH = 900;

    /** @var list<string> */
    private const LOCALES = ['es', 'en'];

    private bool $transientFailure = false;

    public function find(string $artistName): ?string
    {
        $name = trim($artistName);
        if ($name === '') {
            return null;
        }

        $cacheKey = 'artist-bio:v2:'.md5(mb_strtolower($name));

        /** @var array{bio: ?string}|null $cached */
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && array_key_exists('bio', $cached)) {
            return $cached['bio'];
        }

        $this->transientFailure = false;
        $bio = $this->lookup($name);
        if ($bio === null && $this->transientFailure) {
            return null;
        }

        Cache::put(
            $cacheKey,
            ['bio' => $bio],
            $bio === null ? self::MISS_TTL_SECONDS : self::HIT_TTL_SECONDS,
        );

        return $bio;
    }

    private function lookup(string $name): ?string
    {
        foreach (self::LOCALES as $locale) {
            $bio = $this->summaryForTitle($locale, $name);
            if ($bio !== null || $this->transientFailure) {
                return $bio;
            }

            foreach ($this->searchTitles($locale, $name) as $title) {
                if ($this->transientFailure) {
                    return null;
                }
                $bio = $this->summaryForTitle($locale, $title);
                if ($bio !== null || $this->transientFailure) {
                    return $bio;
                }
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function searchTitles(string $locale, string $name): array
    {
        try {
            $response = Http::timeout(8)
                ->withHeaders(['User-Agent' => self::USER_AGENT, 'Accept' => 'application/json'])
                ->get(sprintf('https://%s.wikipedia.org/w/api.php', $locale), [
                    'action' => 'query',
                    'list' => 'search',
                    'srsearch' => $name.' music OR band OR group OR singer OR artista OR banda',
                    'srlimit' => 5,
                    'format' => 'json',
                    'utf8' => 1,
                ]);

            if ($this->isTransient($response->status())) {
                $this->transientFailure = true;

                return [];
            }

            if (! $response->successful()) {
                return [];
            }

            /** @var list<array{title?: string}> $results */
            $results = $response->json('query.search') ?? [];
            $titles = [];
            foreach ($results as $row) {
                $title = isset($row['title']) ? trim((string) $row['title']) : '';
                if ($title !== '') {
                    $titles[] = $title;
                }
            }

            return $titles;
        } catch (Throwable $e) {
            $this->transientFailure = true;
            Log::debug('Wikipedia search failed', ['locale' => $locale, 'error' => $e->getMessage()]);

            return [];
        }
    }

    private function summaryForTitle(string $locale, string $title): ?string
    {
        try {
            $encoded = rawurlencode(str_replace(' ', '_', $title));
            $response = Http::timeout(8)
                ->withHeaders(['User-Agent' => self::USER_AGENT, 'Accept' => 'application/json'])
                ->get(sprintf('https://%s.wikipedia.org/api/rest_v1/page/summary/%s', $locale, $encoded));

            if ($this->isTransient($response->status())) {
                $this->transientFailure = true;

                return null;
            }

            if (! $response->successful()) {
                return null;
            }

            /** @var array<string, mixed> $json */
            $json = $response->json() ?? [];
            $type = (string) ($json['type'] ?? '');
            if ($type === 'disambiguation') {
                return null;
            }

            $extract = trim((string) ($json['extract'] ?? ''));
            if ($extract === '') {
                return null;
            }

            return $this->truncate($extract);
        } catch (Throwable $e) {
            $this->transientFailure = true;
            Log::debug('Wikipedia summary failed', [
                'locale' => $locale,
                'title' => $title,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function isTransient(int $status): bool
    {
        return $status === 403 || $status === 429 || $status >= 500;
    }

    private function truncate(string $text): string
    {
        if (mb_strlen($text) <= self::MAX_LENGTH) {
            return $text;
        }

        $cut = mb_substr($text, 0, self::MAX_LENGTH);
        $lastSpace = mb_strrpos($cut, ' ');
        if ($lastSpace !== false && $lastSpace > (int) (self::MAX_LENGTH * 0.6)) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }

        return rtrim($cut, " \t\n\r\0\x0B.,;:").'…';
    }
}
