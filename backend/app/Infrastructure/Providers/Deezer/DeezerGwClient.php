<?php

declare(strict_types=1);

namespace App\Infrastructure\Providers\Deezer;

use App\Domain\Music\ValueObjects\LyricsPayload;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Authenticated Deezer client using ARL cookie (access_token and/or gw-light).
 * Used for user library endpoints — not for public catalog or FLAC download.
 */
final class DeezerGwClient
{
    /** @var array{user_id: string, access_token: ?string}|null */
    private ?array $sessionCache = null;

    private ?string $cachedArl = null;

    private ?string $tokenArl = null;

    private ?string $cachedApiToken = null;

    public function __construct(
        private DeezerApiClient $api,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function favoriteArtists(string $arl, int $limit = 50, int $index = 0): array
    {
        return $this->libraryPage($arl, 'artists', $limit, $index);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function favoriteAlbums(string $arl, int $limit = 50, int $index = 0): array
    {
        return $this->libraryPage($arl, 'albums', $limit, $index);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function lovedTracks(string $arl, int $limit = 50, int $index = 0): array
    {
        return $this->libraryPage($arl, 'tracks', $limit, $index);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function playlists(string $arl, int $limit = 50, int $index = 0): array
    {
        return $this->libraryPage($arl, 'playlists', $limit, $index);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function libraryPage(string $arl, string $kind, int $limit, int $index): array
    {
        $session = $this->session($arl);
        $limit = max(1, min(100, $limit));
        $index = max(0, $index);

        $path = $session['access_token'] !== null
            ? 'user/me/'.$kind
            : 'user/'.$session['user_id'].'/'.$kind;

        $query = [
            'limit' => $limit,
            'index' => $index,
        ];

        if ($session['access_token'] !== null) {
            $query['access_token'] = $session['access_token'];
        }

        $payload = $this->api->get($path, $query);
        $data = $payload['data'] ?? [];

        if (! is_array($data)) {
            return [];
        }

        $items = [];
        foreach ($data as $row) {
            if (is_array($row)) {
                $items[] = $row;
            }
        }

        return $items;
    }

    /**
     * @return array{user_id: string, access_token: ?string}
     */
    private function session(string $arl): array
    {
        if ($this->cachedArl === $arl && $this->sessionCache !== null) {
            return $this->sessionCache;
        }

        $accessToken = $this->exchangeArlForAccessToken($arl);

        if ($accessToken !== null) {
            try {
                $me = $this->api->get('user/me', ['access_token' => $accessToken]);
                $userId = isset($me['id']) ? (string) $me['id'] : '';
                if ($userId !== '' && $userId !== '0') {
                    $this->sessionCache = [
                        'user_id' => $userId,
                        'access_token' => $accessToken,
                    ];
                    $this->cachedArl = $arl;

                    return $this->sessionCache;
                }
            } catch (Throwable) {
                // Fall through to gw-light.
            }
        }

        $userId = $this->userIdFromGwLight($arl);

        $this->sessionCache = [
            'user_id' => $userId,
            'access_token' => $accessToken,
        ];
        $this->cachedArl = $arl;

        return $this->sessionCache;
    }

    private function exchangeArlForAccessToken(string $arl): ?string
    {
        try {
            $response = Http::timeout(20)
                ->acceptJson()
                ->get('https://auth.deezer.com/login/arl', [
                    'arl' => $arl,
                ]);

            if (! $response->successful()) {
                return null;
            }

            $token = $response->json('access_token');

            return is_string($token) && $token !== '' ? $token : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function userIdFromGwLight(string $arl): string
    {
        $response = Http::timeout(20)
            ->withCookies(['arl' => $arl], 'deezer.com')
            ->acceptJson()
            ->get('https://www.deezer.com/ajax/gw-light.php', [
                'method' => 'deezer.getUserData',
                'input' => '3',
                'api_version' => '1.0',
                'api_token' => '',
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                'Deezer gw-light HTTP %d while validating ARL',
                $response->status(),
            ));
        }

        /** @var array<string, mixed> $json */
        $json = $response->json() ?? [];
        $results = $json['results'] ?? null;

        if (! is_array($results)) {
            throw new RuntimeException('Invalid Deezer ARL or session expired.');
        }

        $user = $results['USER'] ?? null;
        $userId = is_array($user) ? (string) ($user['USER_ID'] ?? '') : '';

        if ($userId === '' || $userId === '0') {
            throw new RuntimeException('Invalid Deezer ARL or session expired.');
        }

        return $userId;
    }

    public function trackLyrics(string $arl, string $trackId): ?LyricsPayload
    {
        if ($arl === '' || $trackId === '') {
            return null;
        }

        try {
            $token = $this->gwApiToken($arl);
            $songId = ctype_digit($trackId) ? (int) $trackId : $trackId;
            $response = Http::timeout(20)
                ->withCookies(['arl' => $arl], 'deezer.com')
                ->acceptJson()
                ->withBody(json_encode(['sng_id' => $songId], JSON_THROW_ON_ERROR), 'application/json')
                ->post('https://www.deezer.com/ajax/gw-light.php?'.http_build_query([
                    'method' => 'song.getLyrics',
                    'input' => '3',
                    'api_version' => '1.0',
                    'api_token' => $token,
                ]));

            if (! $response->successful()) {
                return null;
            }

            /** @var array<string, mixed> $json */
            $json = $response->json() ?? [];
            $results = $json['results'] ?? null;
            if (! is_array($results)) {
                return null;
            }

            $plain = isset($results['LYRICS_TEXT']) && is_string($results['LYRICS_TEXT'])
                ? trim($results['LYRICS_TEXT'])
                : '';
            $synced = $this->syncToLrc($results['LYRICS_SYNC_JSON'] ?? null);
            if ($plain === '' && $synced === null) {
                return null;
            }

            return new LyricsPayload($plain !== '' ? $plain : null, $synced);
        } catch (Throwable) {
            return null;
        }
    }

    private function gwApiToken(string $arl): string
    {
        if ($this->tokenArl === $arl && $this->cachedApiToken !== null) {
            return $this->cachedApiToken;
        }

        $response = Http::timeout(20)
            ->withCookies(['arl' => $arl], 'deezer.com')
            ->acceptJson()
            ->get('https://www.deezer.com/ajax/gw-light.php', [
                'method' => 'deezer.getUserData',
                'input' => '3',
                'api_version' => '1.0',
                'api_token' => '',
            ]);

        $token = '';
        if ($response->successful()) {
            /** @var array<string, mixed> $json */
            $json = $response->json() ?? [];
            $results = $json['results'] ?? null;
            if (is_array($results) && isset($results['checkForm']) && is_string($results['checkForm'])) {
                $token = $results['checkForm'];
            }
        }

        $this->tokenArl = $arl;
        $this->cachedApiToken = $token;

        return $token;
    }

    private function syncToLrc(mixed $sync): ?string
    {
        if (is_string($sync) && $sync !== '') {
            $decoded = json_decode($sync, true);
            $sync = is_array($decoded) ? $decoded : null;
        }

        if (! is_array($sync) || $sync === []) {
            return null;
        }

        $lines = [];
        foreach ($sync as $row) {
            if (! is_array($row)) {
                continue;
            }
            $text = trim((string) ($row['line'] ?? ''));
            if ($text === '') {
                continue;
            }
            $ms = (int) ($row['milliseconds'] ?? 0);
            $minutes = intdiv($ms, 60000);
            $seconds = intdiv($ms % 60000, 1000);
            $hundredths = intdiv($ms % 1000, 10);
            $lines[] = sprintf('[%02d:%02d.%02d]%s', $minutes, $seconds, $hundredths, $text);
        }

        return $lines === [] ? null : implode("\n", $lines);
    }
}
