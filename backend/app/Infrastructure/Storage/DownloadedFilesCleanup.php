<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

use App\Application\Settings\ProviderSettingsResolver;

/**
 * Resolves and deletes files recorded on download jobs, constrained to music_path.
 */
final class DownloadedFilesCleanup
{
    public function __construct(
        private ProviderSettingsResolver $settings,
    ) {}

    /**
     * @param  array<string, mixed>  $job
     * @return list<string>
     */
    public function pathsForJob(array $job): array
    {
        $paths = $this->decodePaths($job['downloaded_paths'] ?? null);

        if ($paths === []) {
            $fallback = $job['destination_path'] ?? null;
            if (is_string($fallback) && $fallback !== '') {
                $paths = [$fallback];
            }
        }

        return $paths;
    }

    /**
     * @param  array<string, mixed>  $job
     */
    public function filesPresent(array $job): bool
    {
        foreach ($this->pathsForJob($job) as $path) {
            if ($this->isSafePath($path) && is_file($path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $job
     */
    public function deleteJobFiles(array $job): void
    {
        foreach ($this->pathsForJob($job) as $path) {
            $this->deletePath($path);
        }
    }

    /**
     * @param  list<string>  $paths
     */
    public function deletePaths(array $paths): void
    {
        foreach ($paths as $path) {
            if (is_string($path) && $path !== '') {
                $this->deletePath($path);
            }
        }
    }

    private function deletePath(string $path): void
    {
        if (! $this->isSafePath($path)) {
            return;
        }

        if (is_file($path)) {
            @unlink($path);
            $this->pruneEmptyParents(dirname($path));

            return;
        }

        if (is_dir($path)) {
            $this->deleteDirectory($path);
            $this->pruneEmptyParents(dirname($path));
        }
    }

    private function deleteDirectory(string $directory): void
    {
        if (! $this->isSafePath($directory) || ! is_dir($directory)) {
            return;
        }

        $items = scandir($directory);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $child = $directory.DIRECTORY_SEPARATOR.$item;
            if (is_dir($child)) {
                $this->deleteDirectory($child);
            } elseif (is_file($child)) {
                @unlink($child);
            }
        }

        @rmdir($directory);
    }

    private function pruneEmptyParents(string $directory): void
    {
        $base = $this->normalizedBase();
        $current = $this->normalize($directory);

        while ($current !== '' && $current !== $base && str_starts_with($current, $base.'/')) {
            if (! is_dir($current)) {
                break;
            }

            $items = scandir($current);
            if ($items === false) {
                break;
            }

            $children = array_values(array_filter($items, fn ($i) => $i !== '.' && $i !== '..'));
            if ($children !== []) {
                break;
            }

            if (! @rmdir($current)) {
                break;
            }

            $current = dirname($current);
        }
    }

    private function isSafePath(string $path): bool
    {
        $normalized = $this->normalize($path);
        $base = $this->normalizedBase();

        if ($normalized === '' || $base === '') {
            return false;
        }

        return $normalized === $base || str_starts_with($normalized, $base.'/');
    }

    private function normalizedBase(): string
    {
        return $this->normalize($this->settings->musicPath());
    }

    private function normalize(string $path): string
    {
        $resolved = realpath($path);
        if ($resolved !== false) {
            return rtrim(str_replace('\\', '/', $resolved), '/');
        }

        // Path may not exist yet / anymore — still constrain by string prefix.
        $clean = str_replace('\\', '/', $path);
        $clean = preg_replace('#/+#', '/', $clean) ?? $clean;

        return rtrim($clean, '/');
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
