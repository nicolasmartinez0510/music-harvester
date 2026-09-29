<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

use App\Application\Settings\ProviderSettingsResolver;

/**
 * Resolves and deletes files recorded on download jobs, constrained to music roots.
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
            foreach ($this->candidatePaths($path) as $candidate) {
                if ($this->isSafePath($candidate) && is_file($candidate)) {
                    return true;
                }
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
            foreach ($this->candidatePaths($path) as $candidate) {
                $this->deletePath($candidate);
            }
        }
    }

    /**
     * @param  list<string>  $paths
     */
    public function deletePaths(array $paths): void
    {
        foreach ($paths as $path) {
            if (is_string($path) && $path !== '') {
                foreach ($this->candidatePaths($path) as $candidate) {
                    $this->deletePath($candidate);
                }
            }
        }
    }

    /**
     * Absolute paths to try for a stored job path (handles host vs container root mismatch).
     *
     * @return list<string>
     */
    private function candidatePaths(string $path): array
    {
        $normalized = $this->normalize($path);
        if ($normalized === '') {
            return [];
        }

        $candidates = [$normalized];

        foreach ($this->musicRoots() as $root) {
            $relative = $this->relativeUnderAnyRoot($normalized);
            if ($relative === null) {
                continue;
            }

            $mapped = $root === '' ? $relative : $root.'/'.$relative;
            if (! in_array($mapped, $candidates, true)) {
                $candidates[] = $mapped;
            }
        }

        return $candidates;
    }

    /**
     * @return list<string>
     */
    private function musicRoots(): array
    {
        $roots = [];
        foreach ([
            $this->settings->musicPath(),
            (string) config('music.path'),
        ] as $root) {
            $normalized = $this->normalize($root);
            if ($normalized !== '' && ! in_array($normalized, $roots, true)) {
                $roots[] = $normalized;
            }
        }

        return $roots;
    }

    private function relativeUnderAnyRoot(string $normalizedPath): ?string
    {
        foreach ($this->musicRoots() as $root) {
            if ($normalizedPath === $root) {
                return '';
            }
            if (str_starts_with($normalizedPath, $root.'/')) {
                return substr($normalizedPath, strlen($root) + 1);
            }
        }

        // Host-style prefix (e.g. /volume1/music/...) when roots are container /music.
        foreach (['/volume1/music', '/volume1/Music'] as $hostRoot) {
            if ($normalizedPath === $hostRoot) {
                return '';
            }
            if (str_starts_with($normalizedPath, $hostRoot.'/')) {
                return substr($normalizedPath, strlen($hostRoot) + 1);
            }
        }

        return null;
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
        $bases = $this->musicRoots();
        $current = $this->normalize($directory);

        while ($current !== '') {
            $isBase = in_array($current, $bases, true);
            if ($isBase) {
                break;
            }

            $underMusic = false;
            foreach ($bases as $base) {
                if (str_starts_with($current, $base.'/')) {
                    $underMusic = true;
                    break;
                }
            }
            if (! $underMusic) {
                break;
            }

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
        foreach ($this->musicRoots() as $root) {
            if ($this->isUnder($path, $root)) {
                return true;
            }
        }

        return $this->isUnder($path, storage_path('app/private/tmp-downloads'));
    }

    private function isUnder(string $path, string $base): bool
    {
        $normalized = $this->normalize($path);
        $root = $this->normalize($base);

        if ($normalized === '' || $root === '') {
            return false;
        }

        return $normalized === $root || str_starts_with($normalized, $root.'/');
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

        // Postgres / drivers may double-encode JSON into a string value.
        if (is_string($decoded)) {
            $decoded = json_decode($decoded, true);
        }

        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, fn ($p) => is_string($p) && $p !== ''));
    }
}
