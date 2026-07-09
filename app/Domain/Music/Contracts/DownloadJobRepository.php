<?php

declare(strict_types=1);

namespace App\Domain\Music\Contracts;

use App\Domain\Music\ValueObjects\AudioFormat;
use App\Domain\Music\ValueObjects\DownloadStatus;

interface DownloadJobRepository
{
    public function create(
        string $provider,
        string $url,
        string $kind,
        AudioFormat $format,
    ): int;

    public function updateStatus(int $id, DownloadStatus $status, ?string $error = null): void;

    public function updateProgress(int $id, int $progress, ?string $destinationPath = null): void;

    /**
     * @return list<array<string, mixed>>
     */
    public function listRecent(int $limit = 50): array;

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array;
}
