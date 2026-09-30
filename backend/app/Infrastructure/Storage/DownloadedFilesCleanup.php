<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

use App\Application\Settings\ProviderSettingsResolver;

/**
 * Resolves and deletes files recorded on download jobs, constrained to music roots.
 */
final class DownloadedFilesCleanup
{
    private const AUDIO_EXTENSIONS = ['flac', 'mp3', 'm4a', 'ogg', 'wav', 'aac', 'opus'];

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

        $fallback = $job['destination_path'] ?? null;
        if (is_string($fallback) && $fallback !== '' && ! in_array($fallback, $paths, true)) {
            $paths[] = $fallback;
        }

        return $paths;
    }

    /**
     * First existing file for a stored path, including host-vs-container root remap.
     * Does not expand to sibling audio in the album folder.
     */
    public function locateExistingFile(string $path): ?string
    {
        foreach ($this->candidatePaths($path) as $candidate) {
            if ($this->isSafePath($candidate) && is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $preservePaths
     */
    private function isPreserved(string $path, array $preservePaths): bool
    {
        if ($preservePaths === []) {
            return false;
        }

        if (in_array($path, $preservePaths, true)) {
            return true;
        }

        foreach ($this->candidatePaths($path) as $candidate) {
            if (in_array($candidate, $preservePaths, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $job
     */
    public function filesPresent(array $job): bool
    {
        return $this->resolveExistingFiles($job) !== [];
    }

    /**
     * @param  array<string, mixed>  $job
     * @param  list<string>  $preservePaths  Paths owned by another download; leave the file in place.
     */
    public function deleteJobFiles(array $job, array $preservePaths = []): void
    {
        $deletedDirs = [];

        foreach ($this->resolveExistingFiles($job) as $path) {
            if ($this->isPreserved($path, $preservePaths)) {
                continue;
            }

            $this->deletePath($path);
            $parent = dirname($path);
            if ($parent !== '' && ! in_array($parent, $deletedDirs, true)) {
                $deletedDirs[] = $parent;
            }
        }

        // Also try exact recorded paths (may already be gone).
        foreach ($this->pathsForJob($job) as $path) {
            if ($this->isPreserved($path, $preservePaths)) {
                continue;
            }

            foreach ($this->candidatePaths($path) as $candidate) {
                if ($this->isPreserved($candidate, $preservePaths)) {
                    continue;
                }

                $this->deletePath($candidate);
            }
        }

        foreach ($deletedDirs as $dir) {
            $this->pruneEmptyParents($dir);
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
     * Make a downloaded file (and its parents up to the music root) readable by php-fpm.
     * Queue workers often run as root on Synology while the app runs as www-data.
     */
    public function relaxPermissions(string $path): void
    {
        if (is_file($path)) {
            @chmod($path, 0664);
        }

        $current = is_dir($path) ? $path : dirname($path);
        $roots = $this->musicRoots();

        while ($current !== '' && $current !== '/' && ! in_array($current, $roots, true)) {
            if (is_dir($current)) {
                @chmod($current, 0775);
            }
            $parent = dirname($current);
            if ($parent === $current) {
                break;
            }
            $current = $parent;
        }
    }

    /**
     * Existing audio files for this job (exact path, remapped host path, or sibling audio in the album dir).
     *
     * @param  array<string, mixed>  $job
     * @return list<string>
     */
    public function resolveExistingFiles(array $job): array
    {
        $found = [];

        foreach ($this->pathsForJob($job) as $path) {
            foreach ($this->candidatePaths($path) as $candidate) {
                if (! $this->isSafePath($candidate)) {
                    continue;
                }

                if (is_file($candidate)) {
                    $found[$candidate] = true;

                    continue;
                }

                if (is_dir($candidate)) {
                    foreach ($this->audioFilesIn($candidate) as $audio) {
                        $found[$audio] = true;
                    }

                    continue;
                }

                $parent = dirname($candidate);
                if ($parent !== $candidate && $this->isSafePath($parent) && is_dir($parent)) {
                    // Never treat the library root as an album folder.
                    if (in_array($this->normalizeString($parent), $this->musicRoots(), true)
                        || in_array($this->normalize($parent), $this->musicRoots(), true)) {
                        continue;
                    }
                    foreach ($this->audioFilesIn($parent) as $audio) {
                        $found[$audio] = true;
                    }
                }
            }
        }

        return array_keys($found);
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

        // Always also try the raw string if realpath rewrote a host path.
        $raw = $this->normalizeString($path);
        if ($raw !== '' && ! in_array($raw, $candidates, true)) {
            $candidates[] = $raw;
        }

        foreach ($this->musicRoots() as $root) {
            $relative = $this->relativeUnderAnyRoot($normalized)
                ?? $this->relativeUnderAnyRoot($raw);
            if ($relative === null) {
                continue;
            }

            $mapped = $relative === '' ? $root : $root.'/'.$relative;
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
            '/music',
        ] as $root) {
            foreach ([$this->normalize($root), $this->normalizeString($root)] as $normalized) {
                if ($normalized !== '' && ! in_array($normalized, $roots, true)) {
                    $roots[] = $normalized;
                }
            }
        }

        return $roots;
    }

    private function relativeUnderAnyRoot(string $normalizedPath): ?string
    {
        if ($normalizedPath === '') {
            return null;
        }

        foreach ($this->musicRoots() as $root) {
            if ($normalizedPath === $root) {
                return '';
            }
            if (str_starts_with($normalizedPath, $root.'/')) {
                return substr($normalizedPath, strlen($root) + 1);
            }
        }

        // Host-style Synology bind source (/volume1/music, /volume2/music, …).
        if (preg_match('#^/volume\d+/music(?:/(.*))?$#i', $normalizedPath, $matches) === 1) {
            return isset($matches[1]) ? (string) $matches[1] : '';
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

            return;
        }

        if (is_dir($path)) {
            $this->deleteDirectory($path);
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
        $current = $this->normalizeString($directory);

        while ($current !== '') {
            if (in_array($current, $bases, true)) {
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

            $parent = dirname($current);
            if ($parent === $current) {
                break;
            }
            $current = $parent;
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
        foreach ([$this->normalize($path), $this->normalizeString($path)] as $normalized) {
            foreach ([$this->normalize($base), $this->normalizeString($base)] as $root) {
                if ($normalized === '' || $root === '') {
                    continue;
                }
                if ($normalized === $root || str_starts_with($normalized, $root.'/')) {
                    return true;
                }
            }
        }

        return false;
    }

    private function normalize(string $path): string
    {
        $resolved = @realpath($path);
        if ($resolved !== false) {
            return rtrim(str_replace('\\', '/', $resolved), '/');
        }

        return $this->normalizeString($path);
    }

    private function normalizeString(string $path): string
    {
        $clean = str_replace('\\', '/', $path);
        $clean = preg_replace('#/+#', '/', $clean) ?? $clean;

        return rtrim($clean, '/');
    }

    /**
     * @return list<string>
     */
    private function audioFilesIn(string $directory): array
    {
        $files = [];
        $items = @scandir($directory);
        if ($items === false) {
            return [];
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $child = $directory.'/'.$item;
            if (! is_file($child)) {
                continue;
            }
            $ext = strtolower(pathinfo($child, PATHINFO_EXTENSION));
            if (in_array($ext, self::AUDIO_EXTENSIONS, true)) {
                $files[] = $child;
            }
        }

        return $files;
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
