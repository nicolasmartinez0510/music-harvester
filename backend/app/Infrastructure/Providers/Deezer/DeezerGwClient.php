<?php

declare(strict_types=1);

namespace App\Infrastructure\Providers\Deezer;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Authenticated Deezer client using ARL cookie (access_token and/or gw-light).
 * Used for user library endpoints — not for public catalog or FLAC download.
 */
final class DeezerGwClient
{
    /** @var array{user_id: string, access_token: ?string}|null */
    private ?array $sessionCache = null;

    private ?string $cachedArl = null;

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
            } catch (\Throwable) {
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
        } catch (\Throwable) {
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
}
