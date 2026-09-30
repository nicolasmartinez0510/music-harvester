<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Music\Contracts\DownloadedTrackRepository;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class EloquentDownloadedTrackRepository implements DownloadedTrackRepository
{
    public function findPresent(?int $userId, string $provider, string $externalId): ?array
    {
        $row = $this->find($userId, $provider, $externalId);
        if ($row === null) {
            return null;
        }

        $path = $row['file_path'] ?? null;
        if (is_string($path) && $path !== '' && is_file($path)) {
            return $row;
        }

        DB::table('downloaded_tracks')->where('id', $row['id'])->delete();

        return null;
    }

    public function findPresentByIdentity(?int $userId, string $artist, string $title, ?int $releaseYear): ?array
    {
        $artist = trim($artist);
        $title = trim($title);
        if ($artist === '' || $title === '') {
            return null;
        }

        $query = DB::table('downloaded_tracks')
            ->whereRaw('lower(trim(artist)) = lower(?)', [$artist])
            ->whereRaw('lower(trim(title)) = lower(?)', [$title])
            ->orderBy('id');

        if ($userId === null) {
            $query->whereNull('user_id');
        } else {
            $query->where('user_id', $userId);
        }

        $present = [];
        foreach ($query->get() as $row) {
            $record = (array) $row;
            $path = $record['file_path'] ?? null;
            if (is_string($path) && $path !== '' && is_file($path)) {
                $present[] = $record;

                continue;
            }

            if (isset($record['id'])) {
                DB::table('downloaded_tracks')->where('id', $record['id'])->delete();
            }
        }

        if ($present === []) {
            return null;
        }

        if (count($present) === 1 || $releaseYear === null) {
            return $present[0];
        }

        $sameYear = array_values(array_filter(
            $present,
            fn (array $row): bool => $this->releaseYearOf($row) === $releaseYear,
        ));
        if ($sameYear !== []) {
            return $sameYear[0];
        }

        $withYear = array_filter(
            $present,
            fn (array $row): bool => $this->releaseYearOf($row) !== null,
        );
        if (count($withYear) === count($present)) {
            return null;
        }

        return $present[0];
    }

    public function findRecorded(?int $userId, string $provider, string $externalId): ?array
    {
        return $this->find($userId, $provider, $externalId);
    }

    public function upsert(
        ?int $userId,
        string $provider,
        string $externalId,
        string $filePath,
        ?string $title = null,
        ?string $artist = null,
        ?int $downloadJobId = null,
        ?int $savedPlaylistTrackId = null,
        ?int $releaseYear = null,
    ): void {
        $now = now();
        $values = [
            'file_path' => $filePath,
            'title' => $title,
            'artist' => $artist,
            'download_job_id' => $downloadJobId,
            'saved_playlist_track_id' => $savedPlaylistTrackId,
            'updated_at' => $now,
        ];
        if ($releaseYear !== null) {
            $values['release_year'] = $releaseYear;
        }

        $existing = $this->scoped($userId, $provider, $externalId);
        if ((clone $existing)->exists()) {
            $existing->update($values);

            return;
        }

        DB::table('downloaded_tracks')->insert([
            'user_id' => $userId,
            'provider' => $provider,
            'external_id' => $externalId,
            ...$values,
            'created_at' => $now,
        ]);
    }

    public function listMissingReleaseYear(): array
    {
        return DB::table('downloaded_tracks')
            ->whereNull('release_year')
            ->orderBy('id')
            ->get()
            ->map(fn ($row): array => (array) $row)
            ->all();
    }

    public function setReleaseYear(int $id, int $releaseYear): void
    {
        DB::table('downloaded_tracks')->where('id', $id)->update([
            'release_year' => $releaseYear,
            'updated_at' => now(),
        ]);
    }

    public function deleteByPaths(array $paths): void
    {
        $paths = array_values(array_filter($paths, fn ($path) => is_string($path) && $path !== ''));
        if ($paths === []) {
            return;
        }

        DB::table('downloaded_tracks')->whereIn('file_path', $paths)->delete();
    }

    public function deleteByJobId(int $downloadJobId): void
    {
        DB::table('downloaded_tracks')->where('download_job_id', $downloadJobId)->delete();
    }

    public function listByProvider(string $provider): array
    {
        return DB::table('downloaded_tracks')
            ->where('provider', $provider)
            ->orderBy('id')
            ->get()
            ->map(fn ($row): array => (array) $row)
            ->all();
    }

    public function isReferencedByAnotherOwner(string $filePath, int $downloadJobId): bool
    {
        return DB::table('downloaded_tracks')
            ->where('file_path', $filePath)
            ->where(function (Builder $query) use ($downloadJobId): void {
                $query->whereNull('download_job_id')
                    ->orWhere('download_job_id', '!=', $downloadJobId);
            })
            ->exists();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function find(?int $userId, string $provider, string $externalId): ?array
    {
        $row = $this->scoped($userId, $provider, $externalId)->first();

        return $row ? (array) $row : null;
    }

    private function scoped(?int $userId, string $provider, string $externalId): Builder
    {
        $query = DB::table('downloaded_tracks')
            ->where('provider', $provider)
            ->where('external_id', $externalId);

        if ($userId === null) {
            $query->whereNull('user_id');
        } else {
            $query->where('user_id', $userId);
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function releaseYearOf(array $row): ?int
    {
        $year = $row['release_year'] ?? null;
        if ($year === null || $year === '') {
            return null;
        }

        return (int) $year;
    }
}
