<?php

declare(strict_types=1);

namespace App\Infrastructure\Downloader;

use App\Domain\Music\Contracts\MusicDownloader;
use App\Domain\Music\ValueObjects\AudioFormat;
use App\Domain\Music\ValueObjects\DownloadOptions;
use App\Domain\Music\ValueObjects\DownloadResult;
use RuntimeException;
use Symfony\Component\Process\Process;

final class YtDlpDownloader implements MusicDownloader
{
    public function __construct(
        private string $binary = 'yt-dlp',
    ) {}

    public function fetchMetadata(string $url, ?string $cookiesPath = null): array
    {
        $args = [
            '--dump-single-json',
            '--no-warnings',
            '--no-progress',
        ];

        if ($this->looksLikePlaylist($url)) {
            $args[] = '--flat-playlist';
        } else {
            $args[] = '--no-playlist';
        }

        $this->appendCookiesArg($args, $cookiesPath);
        $args[] = $url;

        return $this->runJsonCommand($args);
    }

    public function download(string $url, DownloadOptions $options, string $outputTemplate): DownloadResult
    {
        $args = [
            '--extract-audio',
            '--embed-thumbnail',
            '--add-metadata',
            '--no-playlist',
            '--no-overwrites',
            '--no-warnings',
            '--no-progress',
            '--format',
            'bestaudio[protocol=https]/bestaudio[protocol^=http]/bestaudio/best',
            '--downloader',
            'ffmpeg',
            '--retries',
            '10',
            '--fragment-retries',
            '10',
            '--sleep-interval',
            '1',
            '--max-sleep-interval',
            '5',
            '--output',
            $outputTemplate,
        ];

        match ($options->format) {
            AudioFormat::Mp3_320 => array_push($args, '--audio-format', 'mp3', '--audio-quality', '320K'),
            AudioFormat::M4a => array_push($args, '--audio-format', 'm4a'),
        };

        $this->appendCookiesArg($args, $options->cookiesPath);
        $args[] = $url;

        $process = $this->runProcess($args, 3600);

        if (! $process->isSuccessful()) {
            return DownloadResult::failed(trim($process->getErrorOutput() ?: $process->getOutput()));
        }

        $destination = $this->resolveOutputPath($outputTemplate, $options->format);

        if ($destination === null) {
            return DownloadResult::failed('Download finished but output file was not found.');
        }

        return DownloadResult::ok($destination);
    }

    public function isAvailable(): bool
    {
        $process = new Process([$this->binary, '--version']);
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * @param  list<string>  $args
     */
    private function runJsonCommand(array $args): array
    {
        $process = $this->runProcess($args, 300);

        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                'yt-dlp metadata fetch failed: '.trim($process->getErrorOutput() ?: $process->getOutput()),
            );
        }

        $json = json_decode($process->getOutput(), true);

        if (! is_array($json)) {
            throw new RuntimeException('yt-dlp returned invalid JSON.');
        }

        return $json;
    }

    /**
     * @param  list<string>  $args
     */
    private function runProcess(array $args, int $timeout): Process
    {
        $process = new Process(array_merge([$this->binary], $args));
        $process->setTimeout($timeout);
        $process->run();

        return $process;
    }

    /**
     * @param  list<string>  $args
     */
    private function appendCookiesArg(array &$args, ?string $cookiesPath): void
    {
        if ($cookiesPath === null || $cookiesPath === '' || ! is_file($cookiesPath)) {
            return;
        }

        // yt-dlp writes refreshed cookies back to the same path on exit.
        $usablePath = is_writable($cookiesPath)
            ? $cookiesPath
            : $this->writableCookiesCopy($cookiesPath);

        if ($usablePath === null) {
            return;
        }

        $args[] = '--cookies';
        $args[] = $usablePath;
    }

    private function writableCookiesCopy(string $cookiesPath): ?string
    {
        $tempPath = sys_get_temp_dir().'/music-harvester-cookies.txt';

        if (! copy($cookiesPath, $tempPath)) {
            return null;
        }

        return $tempPath;
    }

    private function looksLikePlaylist(string $url): bool
    {
        return (bool) preg_match('#[?&]list=#i', $url)
            || (bool) preg_match('#music\.youtube\.com/(playlist|browse)/#i', $url);
    }

    private function resolveOutputPath(string $outputTemplate, AudioFormat $format): ?string
    {
        $basePath = preg_replace('#\.\%\(ext\)s$#', '', $outputTemplate) ?? $outputTemplate;
        $expected = $basePath.'.'.$format->extension();

        if (is_file($expected)) {
            return $expected;
        }

        $matches = glob($basePath.'.*') ?: [];

        foreach ($matches as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }
}
