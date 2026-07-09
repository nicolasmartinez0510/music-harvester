<?php

declare(strict_types=1);

namespace App\Application\ListDownloads;

use App\Domain\Music\Contracts\DownloadJobRepository;

final readonly class ListDownloadsQuery
{
    public function __construct(
        public int $limit = 50,
    ) {}
}

final readonly class ListDownloadsHandler
{
    public function __construct(
        private DownloadJobRepository $jobs,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function handle(ListDownloadsQuery $query): array
    {
        return $this->jobs->listRecent($query->limit);
    }
}
