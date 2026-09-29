<?php

declare(strict_types=1);

namespace App\Infrastructure\Metadata\Deezer;

use App\Domain\Music\Models\Track;
use App\Domain\Music\ValueObjects\AudioFileMetadata;
use App\Domain\Music\ValueObjects\LyricsPayload;
use App\Domain\Music\ValueObjects\MetadataEnrichContext;
use App\Domain\Music\ValueObjects\ResolvedKind;

/**
 * Maps Deezer public API payloads into tags written on the audio file.
 */
final class DeezerMetadataMapper
{
    /**
     * @param  array<string, mixed>  $trackJson
     * @param  array<string, mixed>  $albumJson
     */
    public function map(
        Track $track,
        array $trackJson,
        array $albumJson,
        ?LyricsPayload $lyrics,
        ?string $coverBytes,
        ?string $coverMime,
        MetadataEnrichContext $context,
    ): AudioFileMetadata {
        $contributors = $this->contributors($trackJson, $albumJson);

        $primary = $this->artistName($trackJson['artist'] ?? null)
            ?? $track->artist?->name;
        $albumArtist = $this->artistName($albumJson['artist'] ?? null) ?? $primary;

        $albumTitle = $this->stringOrNull($albumJson['title'] ?? null)
            ?? $this->nestedString($trackJson, 'album', 'title')
            ?? $track->album?->title;

        $title = $this->stringOrNull($trackJson['title'] ?? null) ?? $track->title;
        $version = $this->stringOrNull($trackJson['title_version'] ?? null);

        $isPlaylist = $context->kind === ResolvedKind::Playlist;
        $trackNumber = $isPlaylist
            ? ($track->index ?? $context->trackTotal)
            : $this->positiveInt($trackJson['track_position'] ?? null) ?? $track->index;
        $trackTotal = $isPlaylist
            ? $context->trackTotal
            : $this->positiveInt($albumJson['nb_tracks'] ?? null);

        return new AudioFileMetadata(
            title: $title,
            titleVersion: $version,
            primaryArtist: $primary,
            albumArtist: $albumArtist,
            featuredArtists: $this->namesByRole($contributors, ['featured', 'featuring', 'feat.']),
            composers: $this->namesByRole($contributors, ['composer', 'writer', 'lyricist', 'songwriter']),
            albumTitle: $albumTitle,
            trackNumber: $trackNumber,
            trackTotal: $trackTotal,
            discNumber: $this->positiveInt($trackJson['disk_number'] ?? null),
            discTotal: $this->positiveInt($albumJson['nb_disks'] ?? $albumJson['nb_disk'] ?? null),
            releaseDate: $this->stringOrNull($albumJson['release_date'] ?? null),
            genres: $this->genres($albumJson),
            isrc: $this->stringOrNull($trackJson['isrc'] ?? null),
            deezerTrackId: $this->stringOrNull($trackJson['id'] ?? null) ?? $track->id,
            coverBytes: $coverBytes,
            coverMime: $coverBytes !== null ? ($coverMime ?? 'image/jpeg') : null,
            lyricsPlain: $lyrics?->plain,
            lyricsSynced: $lyrics?->synced,
        );
    }

    /**
     * @param  array<string, mixed>  $trackJson
     * @param  array<string, mixed>  $albumJson
     * @return list<array<string, mixed>>
     */
    private function contributors(array $trackJson, array $albumJson): array
    {
        $rows = [];
        foreach ([$trackJson['contributors'] ?? null, $albumJson['contributors'] ?? null] as $list) {
            if (! is_array($list)) {
                continue;
            }
            foreach ($list as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $contributors
     * @param  list<string>  $roles
     * @return list<string>
     */
    private function namesByRole(array $contributors, array $roles): array
    {
        $names = [];
        foreach ($contributors as $row) {
            $role = strtolower(trim((string) ($row['role'] ?? '')));
            $name = trim((string) ($row['name'] ?? ''));
            if ($role === '' || $name === '') {
                continue;
            }
            foreach ($roles as $needle) {
                if ($role === $needle || str_contains($role, $needle)) {
                    $names[] = $name;
                    break;
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @param  array<string, mixed>  $albumJson
     * @return list<string>
     */
    private function genres(array $albumJson): array
    {
        $genres = $albumJson['genres'] ?? null;
        $data = is_array($genres) ? ($genres['data'] ?? $genres) : null;
        if (! is_array($data)) {
            $single = $this->stringOrNull($albumJson['genre'] ?? null);
            if ($single === null && isset($albumJson['genre']) && is_array($albumJson['genre'])) {
                $single = $this->stringOrNull($albumJson['genre']['name'] ?? null);
            }

            return $single === null ? [] : [$single];
        }

        $names = [];
        foreach ($data as $row) {
            if (is_string($row) && trim($row) !== '') {
                $names[] = trim($row);
            } elseif (is_array($row)) {
                $name = $this->stringOrNull($row['name'] ?? null);
                if ($name !== null) {
                    $names[] = $name;
                }
            }
        }

        return array_values(array_unique($names));
    }

    private function artistName(mixed $artist): ?string
    {
        if (is_string($artist)) {
            return $this->stringOrNull($artist);
        }
        if (! is_array($artist)) {
            return null;
        }

        return $this->stringOrNull($artist['name'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function nestedString(array $payload, string $key, string $child): ?string
    {
        $nested = $payload[$key] ?? null;
        if (! is_array($nested)) {
            return null;
        }

        return $this->stringOrNull($nested[$child] ?? null);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function positiveInt(mixed $value): ?int
    {
        if (! is_int($value) && ! is_string($value)) {
            return null;
        }
        if (is_string($value) && ! is_numeric($value)) {
            return null;
        }
        $number = (int) $value;

        return $number > 0 ? $number : null;
    }
}
