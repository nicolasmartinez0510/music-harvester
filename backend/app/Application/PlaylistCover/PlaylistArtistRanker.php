<?php

declare(strict_types=1);

namespace App\Application\PlaylistCover;

/**
 * Counts the stored primary artist name. A clear winner has at least three
 * tracks and more than the next artist. A playlist with a single artist is
 * also clear, even with fewer tracks.
 */
final class PlaylistArtistRanker
{
    /**
     * @param  list<string|null>  $artists
     * @return array{clear: string|null, top: list<string>}
     */
    public function rank(array $artists): array
    {
        /** @var array<string, array{name: string, count: int, order: int}> $counts */
        $counts = [];
        $order = 0;

        foreach ($artists as $artist) {
            if (! is_string($artist)) {
                continue;
            }

            $display = trim((string) (preg_replace('/\s+/u', ' ', $artist) ?? ''));
            if ($display === '' || mb_strtolower($display) === 'unknown artist') {
                continue;
            }

            $key = mb_strtolower($display);
            if (! isset($counts[$key])) {
                $counts[$key] = ['name' => $display, 'count' => 0, 'order' => $order];
                $order++;
            }

            $counts[$key]['count']++;
        }

        $ranked = array_values($counts);
        usort($ranked, function (array $left, array $right): int {
            if ($left['count'] !== $right['count']) {
                return $right['count'] <=> $left['count'];
            }

            return $left['order'] <=> $right['order'];
        });

        $top = array_map(
            static fn (array $row): string => $row['name'],
            array_slice($ranked, 0, 4),
        );

        $clear = null;
        if ($ranked !== []) {
            $first = $ranked[0];
            $secondCount = $ranked[1]['count'] ?? 0;
            $onlyArtist = count($ranked) === 1;
            if ($onlyArtist || ($first['count'] >= 3 && $first['count'] > $secondCount)) {
                $clear = $first['name'];
            }
        }

        return [
            'clear' => $clear,
            'top' => $top,
        ];
    }
}
