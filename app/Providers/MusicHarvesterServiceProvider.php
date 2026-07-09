<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\CreateDownload\CreateDownloadHandler;
use App\Application\DownloadPlaylist\DownloadPlaylistHandler;
use App\Application\DownloadTrack\DownloadTrackHandler;
use App\Application\GetSettings\GetSettingsHandler;
use App\Application\ListDownloads\ListDownloadsHandler;
use App\Application\RetryDownload\RetryDownloadHandler;
use App\Application\UpdateSettings\UpdateSettingsHandler;
use App\Domain\Music\Contracts\DownloadJobRepository;
use App\Domain\Music\Contracts\SettingsRepository;
use App\Domain\Music\Contracts\MusicDownloader;
use App\Domain\Music\Contracts\MusicProvider;
use App\Infrastructure\Downloader\YtDlpDownloader;
use App\Infrastructure\Persistence\EloquentDownloadRepository;
use App\Infrastructure\Persistence\EloquentSettingsRepository;
use App\Infrastructure\Providers\MusicProviderRegistry;
use App\Infrastructure\Providers\YoutubeMusic\YoutubeMusicProvider;
use App\Infrastructure\Storage\LocalMusicStorage;
use Illuminate\Support\ServiceProvider;

class MusicHarvesterServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DownloadJobRepository::class, EloquentDownloadRepository::class);
        $this->app->singleton(SettingsRepository::class, EloquentSettingsRepository::class);

        $this->app->singleton(LocalMusicStorage::class, function ($app) {
            return new LocalMusicStorage(config('music.path'));
        });

        $this->app->singleton(YtDlpDownloader::class);
        $this->app->bind(MusicDownloader::class, YtDlpDownloader::class);

        $this->app->singleton(YoutubeMusicProvider::class, function ($app) {
            $cookiesPath = config('music.cookies_path');

            return new YoutubeMusicProvider(
                $app->make(MusicDownloader::class),
                $app->make(LocalMusicStorage::class),
                is_string($cookiesPath) && $cookiesPath !== '' ? $cookiesPath : null,
            );
        });
        $this->app->tag([YoutubeMusicProvider::class], 'music.providers');

        $this->app->singleton(MusicProviderRegistry::class, function ($app) {
            return new MusicProviderRegistry(
                $app->tagged('music.providers'),
            );
        });

        $this->app->singleton(DownloadTrackHandler::class);
        $this->app->singleton(DownloadPlaylistHandler::class);
        $this->app->singleton(CreateDownloadHandler::class);
        $this->app->singleton(RetryDownloadHandler::class);
        $this->app->singleton(ListDownloadsHandler::class);
        $this->app->singleton(GetSettingsHandler::class);
        $this->app->singleton(UpdateSettingsHandler::class);

        $this->app->bind(MusicProvider::class, YoutubeMusicProvider::class);
    }

    public function boot(): void
    {
        //
    }
}
