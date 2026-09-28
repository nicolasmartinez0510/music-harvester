<?php

declare(strict_types=1);

namespace App\Infrastructure\Providers\Deezer;

use App\Domain\Music\ValueObjects\AudioFormat;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Downloads Deezer tracks via streamrip (`rip`) using an ARL cookie.
 *
 * Quality mapping: FLAC → 2, MP3 320 → 1, else → 1.
 */
final class StreamripDeezerDownloader
{
    public function __construct(
        private string $ripBinary = 'rip',
    ) {}

    public function download(string $deezerUrl, string $arl, AudioFormat $format, string $outputDirectory): string
    {
        if (! is_dir($outputDirectory) && ! mkdir($outputDirectory, 0755, true) && ! is_dir($outputDirectory)) {
            throw new RuntimeException('Cannot create output directory: '.$outputDirectory);
        }

        $configHome = sys_get_temp_dir().'/music-harvester-streamrip-'.bin2hex(random_bytes(8));
        $configDir = $configHome.'/.config/streamrip';
        if (! mkdir($configDir, 0700, true) && ! is_dir($configDir)) {
            throw new RuntimeException('Cannot create streamrip config directory.');
        }

        try {
            $configPath = $configDir.'/config.toml';
            file_put_contents($configPath, $this->buildConfig($arl, $format, $outputDirectory));

            $before = $this->listFiles($outputDirectory);

            $process = new Process([
                $this->ripBinary,
                'url',
                $deezerUrl,
            ], null, [
                'HOME' => $configHome,
                'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
            ]);
            $process->setTimeout(600);
            $process->run();

            if (! $process->isSuccessful()) {
                throw new RuntimeException(trim($process->getErrorOutput() ?: $process->getOutput())
                    ?: 'streamrip download failed.');
            }

            $after = $this->listFiles($outputDirectory);
            $newFiles = array_values(array_diff($after, $before));

            if ($newFiles === []) {
                $nested = $this->findNewestAudioFile($outputDirectory, $before);
                if ($nested === null) {
                    throw new RuntimeException('streamrip finished but no audio file was produced.');
                }

                return $nested;
            }

            usort($newFiles, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

            return $newFiles[0];
        } finally {
            $this->removeDirectory($configHome);
        }
    }

    private function buildConfig(string $arl, AudioFormat $format, string $outputDirectory): string
    {
        $quality = match ($format) {
            AudioFormat::Flac => 2,
            AudioFormat::Mp3_320, AudioFormat::M4a => 1,
        };

        $downloads = addslashes($outputDirectory);
        $arlEscaped = addslashes($arl);

        return <<<TOML
[downloads]
folder = "{$downloads}"
concurrency = 1

[deezer]
arl = "{$arlEscaped}"
quality = {$quality}
lower_quality_if_not_available = true
use_deezloader = false

[cli]
text_output = true

[misc]
version = "2.0.0"
TOML;
    }

    /**
     * @return list<string>
     */
    private function listFiles(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $this->isAudioExtension($file->getExtension())) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * @param  list<string>  $before
     */
    private function findNewestAudioFile(string $directory, array $before): ?string
    {
        $after = $this->listFiles($directory);
        $new = array_values(array_diff($after, $before));

        if ($new === []) {
            return null;
        }

        usort($new, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        return $new[0];
    }

    private function isAudioExtension(string $ext): bool
    {
        return in_array(strtolower($ext), ['flac', 'mp3', 'm4a', 'ogg', 'wav'], true);
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }

        @rmdir($directory);
    }
}
