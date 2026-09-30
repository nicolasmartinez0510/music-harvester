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
}
