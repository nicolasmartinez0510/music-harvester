<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Catalog\GetCatalogAlbumHandler;
use App\Application\Catalog\GetCatalogArtistHandler;
use App\Application\Catalog\GetCatalogPlaylistHandler;
use App\Application\Catalog\SearchCatalogHandler;
use App\Application\ClearDownloads\ClearDownloadsHandler;
use App\Application\CreateDownload\CreateDownloadHandler;
use App\Application\DeleteDownload\DeleteDownloadHandler;
use App\Application\DeleteSavedPlaylist\DeleteSavedPlaylistHandler;
use App\Application\DownloadPlaylist\DownloadPlaylistHandler;
use App\Application\DownloadTrack\DownloadTrackHandler;
use App\Application\GetSavedPlaylist\GetSavedPlaylistHandler;
use App\Application\GetSettings\GetSettingsHandler;
use App\Application\ListDownloads\ListDownloadsHandler;
use App\Application\ListProviders\ListProvidersHandler;
use App\Application\ListSavedPlaylists\ListSavedPlaylistsHandler;
use App\Application\RetryDownload\RetryDownloadHandler;
use App\Application\SavePlaylist\SavePlaylistHandler;
use App\Application\Settings\ProviderSettingsResolver;
use App\Application\SyncSavedPlaylist\SyncSavedPlaylistHandler;
use App\Application\UpdateSavedPlaylist\UpdateSavedPlaylistHandler;
use App\Application\UpdateSettings\UpdateSettingsHandler;
use App\Domain\Music\Contracts\DownloadJobRepository;
use App\Domain\Music\Contracts\ArtistBiographyLookup;
use App\Domain\Music\Contracts\MusicDownloader;
use App\Domain\Music\Contracts\MusicProvider;
use App\Domain\Music\Contracts\SavedPlaylistRepository;
use App\Domain\Music\Contracts\SettingsRepository;
use App\Infrastructure\Biography\WikipediaArtistBiographyLookup;
use App\Infrastructure\Downloader\YtDlpDownloader;
use App\Infrastructure\Persistence\EloquentDownloadRepository;
use App\Infrastructure\Persistence\EloquentSavedPlaylistRepository;
use App\Infrastructure\Persistence\EloquentSettingsRepository;
use App\Application\Library\GetUserLibraryHandler;
use App\Infrastructure\Providers\CatalogSourceRegistry;
use App\Infrastructure\Providers\Deezer\DeezerApiClient;
use App\Infrastructure\Providers\Deezer\DeezerGwClient;
use App\Infrastructure\Providers\Deezer\DeezerProvider;
use App\Infrastructure\Providers\Deezer\StreamripDeezerDownloader;
use App\Infrastructure\Providers\MusicProviderRegistry;
use App\Infrastructure\Providers\UserLibrarySourceRegistry;
use App\Infrastructure\Providers\YoutubeMusic\YoutubeMusicMatcher;
use App\Infrastructure\Providers\YoutubeMusic\YoutubeMusicProvider;
use App\Infrastructure\Storage\DownloadedFilesCleanup;
use App\Infrastructure\Storage\LocalMusicStorage;
use Illuminate\Support\ServiceProvider;

class MusicHarvesterServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DownloadJobRepository::class, EloquentDownloadRepository::class);
        $this->app->singleton(SavedPlaylistRepository::class, EloquentSavedPlaylistRepository::class);
        $this->app->singleton(SettingsRepository::class, EloquentSettingsRepository::class);
        $this->app->singleton(ArtistBiographyLookup::class, WikipediaArtistBiographyLookup::class);
        $this->app->singleton(ProviderSettingsResolver::class);

        $this->app->singleton(LocalMusicStorage::class, function ($app) {
            return new LocalMusicStorage(config('music.path'));
        });

        $this->app->singleton(DownloadedFilesCleanup::class);

        $this->app->singleton(YtDlpDownloader::class);
        $this->app->bind(MusicDownloader::class, YtDlpDownloader::class);
        $this->app->singleton(YoutubeMusicMatcher::class);
        $this->app->singleton(DeezerApiClient::class);
        $this->app->singleton(DeezerGwClient::class);

        $this->app->singleton(StreamripDeezerDownloader::class, function () {
            return new StreamripDeezerDownloader(
                (string) config('music.streamrip_bin', 'rip'),
            );
        });

        $this->app->singleton(YoutubeMusicProvider::class, function ($app) {
            /** @var ProviderSettingsResolver $settings */
            $settings = $app->make(ProviderSettingsResolver::class);

            return new YoutubeMusicProvider(
                $app->make(MusicDownloader::class),
                $app->make(LocalMusicStorage::class),
                $settings->youtubeMusicCookiesPath(),
            );
        });

        $this->app->singleton(DeezerProvider::class);

        $this->app->tag([YoutubeMusicProvider::class, DeezerProvider::class], 'music.providers');
        $this->app->tag([DeezerProvider::class], 'music.catalog_sources');
        $this->app->tag([DeezerProvider::class], 'music.user_library_sources');

        $this->app->singleton(MusicProviderRegistry::class, function ($app) {
            return new MusicProviderRegistry(
                $app->tagged('music.providers'),
                $app->make(ProviderSettingsResolver::class),
            );
        });

        $this->app->singleton(CatalogSourceRegistry::class, function ($app) {
            return new CatalogSourceRegistry(
                $app->tagged('music.catalog_sources'),
                $app->make(ProviderSettingsResolver::class),
            );
        });

        $this->app->singleton(UserLibrarySourceRegistry::class, function ($app) {
            return new UserLibrarySourceRegistry(
                $app->tagged('music.user_library_sources'),
                $app->make(ProviderSettingsResolver::class),
            );
        });

        $this->app->singleton(SearchCatalogHandler::class);
        $this->app->singleton(GetCatalogArtistHandler::class);
        $this->app->singleton(GetCatalogAlbumHandler::class);
        $this->app->singleton(GetCatalogPlaylistHandler::class);
        $this->app->singleton(GetUserLibraryHandler::class);

        $this->app->singleton(DownloadTrackHandler::class);
        $this->app->singleton(DownloadPlaylistHandler::class);
        $this->app->singleton(CreateDownloadHandler::class);
        $this->app->singleton(RetryDownloadHandler::class);
        $this->app->singleton(ListDownloadsHandler::class);
        $this->app->singleton(DeleteDownloadHandler::class);
        $this->app->singleton(ClearDownloadsHandler::class);
        $this->app->singleton(ListProvidersHandler::class);
        $this->app->singleton(GetSettingsHandler::class);
        $this->app->singleton(UpdateSettingsHandler::class);
        $this->app->singleton(SavePlaylistHandler::class);
        $this->app->singleton(ListSavedPlaylistsHandler::class);
        $this->app->singleton(GetSavedPlaylistHandler::class);
        $this->app->singleton(UpdateSavedPlaylistHandler::class);
        $this->app->singleton(DeleteSavedPlaylistHandler::class);
        $this->app->singleton(SyncSavedPlaylistHandler::class);

        $this->app->bind(MusicProvider::class, YoutubeMusicProvider::class);
    }

    public function boot(): void
    {
        //
    }
}
