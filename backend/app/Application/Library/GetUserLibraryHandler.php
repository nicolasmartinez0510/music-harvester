<?php

declare(strict_types=1);

namespace App\Application\Library;

use App\Domain\Music\Contracts\UserLibrarySource;
use App\Domain\Music\ValueObjects\CatalogHit;
use App\Infrastructure\Providers\UserLibrarySourceRegistry;
use InvalidArgumentException;
use RuntimeException;

final readonly class GetUserLibraryQuery
{
    public function __construct(
        public string $provider,
        public string $kind,
        public int $limit = 50,
        public int $index = 0,
    ) {}
}

final readonly class GetUserLibraryHandler
{
    private const KINDS = ['artists', 'albums', 'tracks', 'playlists'];

    public function __construct(
        private UserLibrarySourceRegistry $librarySources,
    ) {}

    /**
     * @return list<CatalogHit>
     */
    public function handle(GetUserLibraryQuery $query): array
    {
        if (! in_array($query->kind, self::KINDS, true)) {
            throw new InvalidArgumentException(sprintf(
                'Unknown library kind [%s]. Expected one of: %s.',
                $query->kind,
                implode(', ', self::KINDS),
            ));
        }

        $source = $this->requireSource($query->provider);

        if (! $source->isLibraryAvailable()) {
            throw new RuntimeException(sprintf(
                'Library for provider [%s] is not available. Configure credentials (e.g. Deezer ARL).',
                $query->provider,
            ));
        }

        $limit = max(1, min(100, $query->limit));
        $index = max(0, $query->index);

        return match ($query->kind) {
            'artists' => $source->favoriteArtists($limit, $index),
            'albums' => $source->favoriteAlbums($limit, $index),
            'tracks' => $source->lovedTracks($limit, $index),
            'playlists' => $source->playlists($limit, $index),
        };
    }

    private function requireSource(string $provider): UserLibrarySource
    {
        $source = $this->librarySources->findByName($provider);

        if ($source === null) {
            throw new InvalidArgumentException(sprintf(
                'Library provider [%s] is not available.',
                $provider,
            ));
        }

        return $source;
    }
}
