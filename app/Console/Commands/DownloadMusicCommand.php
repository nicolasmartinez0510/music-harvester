<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\DownloadPlaylist\DownloadPlaylistCommand;
use App\Application\DownloadPlaylist\DownloadPlaylistHandler;
use App\Application\DownloadTrack\DownloadTrackCommand;
use App\Application\DownloadTrack\DownloadTrackHandler;
use App\Domain\Music\ValueObjects\AudioFormat;
use App\Domain\Music\ValueObjects\MusicUrl;
use App\Infrastructure\Downloader\YtDlpDownloader;
use App\Infrastructure\Providers\MusicProviderRegistry;
use App\Jobs\ProcessDownloadJob;
use Illuminate\Console\Command;

class DownloadMusicCommand extends Command
{
    protected $signature = 'music:download
        {url : YouTube Music URL (track, playlist, or album)}
        {--format= : Audio format (mp3_320 or m4a)}
        {--sync : Process immediately in this process instead of queueing}';

    protected $description = 'Enqueue a download from a YouTube Music URL';

    public function handle(
        DownloadTrackHandler $trackHandler,
        DownloadPlaylistHandler $playlistHandler,
        MusicProviderRegistry $providers,
        YtDlpDownloader $downloader,
    ): int {
        if (! $downloader->isAvailable()) {
            $this->error('yt-dlp is not available in PATH.');

            return self::FAILURE;
        }

        $url = (string) $this->argument('url');
        $provider = $providers->resolveForUrl($url);

        if ($provider === null) {
            $this->error('No provider supports this URL.');

            return self::FAILURE;
        }

        $format = $this->resolveFormat();
        $musicUrl = new MusicUrl($url);
        $isPlaylist = (bool) preg_match('#[?&]list=#i', $url)
            || (bool) preg_match('#music\.youtube\.com/(playlist|browse)/#i', $url);

        $jobId = $isPlaylist
            ? $playlistHandler->handle(new DownloadPlaylistCommand($musicUrl, $format))
            : $trackHandler->handle(new DownloadTrackCommand($musicUrl, $format));

        $this->info("Download job #{$jobId} created.");

        if ($this->option('sync')) {
            $this->info('Processing synchronously...');
            dispatch_sync(new ProcessDownloadJob($jobId));
            $this->info('Done.');

            return self::SUCCESS;
        }

        ProcessDownloadJob::dispatch($jobId);
        $this->info('Job dispatched to the queue. Run `php artisan queue:work` to process it.');

        return self::SUCCESS;
    }

    private function resolveFormat(): AudioFormat
    {
        $formatOption = $this->option('format');

        if (is_string($formatOption) && $formatOption !== '') {
            $format = AudioFormat::tryFrom($formatOption);

            if ($format === null) {
                $this->warn("Unknown format '{$formatOption}', using default.");
            } else {
                return $format;
            }
        }

        return AudioFormat::tryFrom((string) config('music.default_format'))
            ?? AudioFormat::Mp3_320;
    }
}
