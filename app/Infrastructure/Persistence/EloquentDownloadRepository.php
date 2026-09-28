<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Music\Contracts\DownloadJobRepository;
use App\Domain\Music\ValueObjects\AudioFormat;
use App\Domain\Music\ValueObjects\DownloadStatus;
use Illuminate\Support\Facades\DB;

final class EloquentDownloadRepository implements DownloadJobRepository
{
    public function create(
        string $provider,
        string $url,
        string $kind,
        AudioFormat $format,
    ): int {
        return (int) DB::table('download_jobs')->insertGetId([
            'provider' => $provider,
            'url' => $url,
            'kind' => $kind,
            'status' => DownloadStatus::Pending->value,
            'progress' => 0,
            'options_json' => json_encode(['format' => $format->value]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function updateStatus(int $id, DownloadStatus $status, ?string $error = null): void
    {
        DB::table('download_jobs')->where('id', $id)->update([
            'status' => $status->value,
            'error' => $error,
            'updated_at' => now(),
        ]);
    }

    public function updateProgress(int $id, int $progress, ?string $destinationPath = null): void
    {
        $data = [
            'progress' => $progress,
            'updated_at' => now(),
        ];

        if ($destinationPath !== null) {
            $data['destination_path'] = $destinationPath;
        }

        DB::table('download_jobs')->where('id', $id)->update($data);
    }

    public function updateMetadata(int $id, string $title, ?string $artist): void
    {
        DB::table('download_jobs')->where('id', $id)->update([
            'title' => $title,
            'artist' => $artist,
            'updated_at' => now(),
        ]);
    }

    public function appendDownloadedPath(int $id, string $path): void
    {
        $job = $this->find($id);
        if ($job === null) {
            return;
        }

        $paths = $this->decodePaths($job['downloaded_paths'] ?? null);
        if (! in_array($path, $paths, true)) {
            $paths[] = $path;
        }

        DB::table('download_jobs')->where('id', $id)->update([
            'downloaded_paths' => json_encode(array_values($paths)),
            'destination_path' => $path,
            'updated_at' => now(),
        ]);
    }

    public function listRecent(int $limit = 50): array
    {
        return DB::table('download_jobs')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    public function listAll(): array
    {
        return DB::table('download_jobs')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    public function find(int $id): ?array
    {
        $row = DB::table('download_jobs')->where('id', $id)->first();

        return $row ? (array) $row : null;
    }

    public function delete(int $id): bool
    {
        return DB::table('download_jobs')->where('id', $id)->delete() > 0;
    }

    public function deleteAll(): int
    {
        return (int) DB::table('download_jobs')->delete();
    }

    /**
     * @return list<string>
     */
    private function decodePaths(mixed $raw): array
    {
        if (is_array($raw)) {
            return array_values(array_filter($raw, fn ($p) => is_string($p) && $p !== ''));
        }

        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, fn ($p) => is_string($p) && $p !== ''));
    }
}
