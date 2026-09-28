<?php

declare(strict_types=1);

namespace App\Application\SavePlaylist;

use App\Domain\Music\Contracts\SavedPlaylistRepository;
use App\Domain\Music\Exceptions\UnsupportedMusicUrlException;
use App\Domain\Music\ValueObjects\AudioFormat;
use App\Domain\Music\ValueObjects\MusicUrl;
use App\Infrastructure\Providers\MusicProviderRegistry;
use App\Jobs\ProcessPlaylistSyncJob;

final readonly class SavePlaylistCommand
{
    public function __construct(
        public MusicUrl $url,
        public bool $syncNow = true,
        public bool $syncEnabled = true,
        public ?int $syncIntervalMinutes = null,
        public ?AudioFormat $defaultFormat = null,
    ) {}
}

final readonly class SavePlaylistHandler
{
    public function __construct(
        private SavedPlaylistRepository $playlists,
        private MusicProviderRegistry $providers,
    ) {}

    /**
     * @return array{playlist: array<string, mixed>, created: bool}
     */
    public function handle(SavePlaylistCommand $command): array
    {
        $url = (string) $command->url;
        $existing = $this->playlists->findByUrl($url);

        if ($existing !== null) {
            if ($command->syncNow && ($existing['last_sync_status'] ?? '') !== 'running') {
                ProcessPlaylistSyncJob::dispatch((int) $existing['id']);
            }

            return [
                'playlist' => $existing,
                'created' => false,
            ];
        }

        $provider = $this->providers->resolveForUrl($url);

        if ($provider === null) {
            throw UnsupportedMusicUrlException::forUrl($url);
        }

        $interval = $command->syncIntervalMinutes
            ?? (int) config('music.default_sync_interval_minutes', 5);

        $playlist = $this->playlists->create(
            provider: $provider->name(),
            url: $url,
            title: null,
            syncEnabled: $command->syncEnabled,
            syncIntervalMinutes: max(1, $interval),
            defaultFormat: $command->defaultFormat,
        );

        if ($command->syncNow) {
            ProcessPlaylistSyncJob::dispatch((int) $playlist['id']);
        }

        return [
            'playlist' => $playlist,
            'created' => true,
        ];
    }
}
