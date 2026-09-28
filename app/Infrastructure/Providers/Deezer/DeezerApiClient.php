<?php

declare(strict_types=1);

namespace App\Infrastructure\Providers\Deezer;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin client for the public Deezer API (catalog only — no audio).
 */
final class DeezerApiClient
{
    private const BASE = 'https://api.deezer.com';

    /**
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        $response = Http::timeout(20)
            ->acceptJson()
            ->get(self::BASE.'/'.ltrim($path, '/'), $query);

        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                'Deezer API HTTP %d for %s',
                $response->status(),
                $path,
            ));
        }

        /** @var array<string, mixed> $json */
        $json = $response->json() ?? [];

        if (isset($json['error']) && is_array($json['error'])) {
            $message = (string) ($json['error']['message'] ?? 'Unknown Deezer API error');
            throw new RuntimeException('Deezer API error: '.$message);
        }

        return $json;
    }

    /**
     * Fetch all pages for endpoints that return { data: [], total, next }.
     *
     * @return list<array<string, mixed>>
     */
    public function getAllData(string $path, array $query = [], int $maxItems = 500): array
    {
        $items = [];
        $index = 0;
        $limit = min(100, (int) ($query['limit'] ?? 100));

        while (count($items) < $maxItems) {
            $page = $this->get($path, array_merge($query, [
                'index' => $index,
                'limit' => $limit,
            ]));

            $data = $page['data'] ?? [];
            if (! is_array($data) || $data === []) {
                break;
            }

            foreach ($data as $row) {
                if (is_array($row)) {
                    $items[] = $row;
                }
            }

            $total = (int) ($page['total'] ?? count($items));
            $index += $limit;

            if ($index >= $total || ! isset($page['next'])) {
                break;
            }
        }

        return array_slice($items, 0, $maxItems);
    }
}
