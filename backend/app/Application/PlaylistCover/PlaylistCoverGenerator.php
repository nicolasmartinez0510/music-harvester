<?php

declare(strict_types=1);

namespace App\Application\PlaylistCover;

use App\Application\Auth\LibraryPathResolver;
use App\Domain\Music\Contracts\ArtistPortraitLookup;
use App\Domain\Music\Contracts\PlaylistCoverRenderer;
use App\Domain\Music\Contracts\SavedPlaylistRepository;
use App\Domain\Music\ValueObjects\PlaylistCoverMode;
use App\Domain\Music\ValueObjects\PlaylistTrackStatus;
use App\Infrastructure\Storage\LocalMusicStorage;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final readonly class PlaylistCoverGenerator
{
    private const SIZE = 1000;

    public function __construct(
        private SavedPlaylistRepository $playlists,
        private LocalMusicStorage $storage,
        private LibraryPathResolver $paths,
        private PlaylistCoverRenderer $renderer,
        private ArtistPortraitLookup $portraits,
        private PlaylistArtistRanker $ranker,
    ) {}

    public function generate(int $playlistId): void
    {
        try {
            $this->render($playlistId);
        } catch (Throwable $exception) {
            Log::warning('playlist cover generation failed', [
                'playlist_id' => $playlistId,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function storeCustom(int $playlistId, string $sourcePath): array
    {
        $playlist = $this->playlists->find($playlistId);
        if ($playlist === null) {
            throw new RuntimeException('Playlist not found.');
        }

        if (! is_file($sourcePath)) {
            throw new RuntimeException('Cover upload is missing.');
        }

        $customPath = $this->customPath($playlistId);
        $this->ensureParent($customPath);

        $rendered = $this->renderer->render([
            'mode' => 'upload',
            'title' => $this->titleOf($playlist),
            'playlist_id' => $playlistId,
            'output' => $customPath,
            'image_paths' => [$sourcePath],
            'size' => self::SIZE,
        ]);

        if (! $rendered['ok'] || ! is_file($customPath)) {
            throw new RuntimeException('No se pudo procesar la imagen.');
        }

        @chmod($customPath, 0644);
        $this->publishFile($customPath, $this->prepareOutput($playlist));

        $updated = $this->playlists->update($playlistId, [
            'cover_mode' => PlaylistCoverMode::Custom->value,
        ]);

        if ($updated === null) {
            throw new RuntimeException('Playlist not found.');
        }

        return $updated;
    }

    public function hasCustom(int $playlistId): bool
    {
        return is_file($this->customPath($playlistId));
    }

    public function deleteCustom(int $playlistId): void
    {
        $path = $this->customPath($playlistId);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function readablePath(int $playlistId): ?string
    {
        $playlist = $this->playlists->find($playlistId);
        if ($playlist === null) {
            return null;
        }

        $published = $this->publishedPath($playlist);
        if (is_file($published)) {
            return $published;
        }

        $custom = $this->customPath($playlistId);

        return is_file($custom) ? $custom : null;
    }

    public function customPath(int $playlistId): string
    {
        return storage_path('app/private/playlist-covers/'.$playlistId.'.jpg');
    }

    private function render(int $playlistId): void
    {
        $playlist = $this->playlists->find($playlistId);
        if ($playlist === null) {
            return;
        }

        $mode = PlaylistCoverMode::tryFrom((string) ($playlist['cover_mode'] ?? PlaylistCoverMode::Auto->value))
            ?? PlaylistCoverMode::Auto;
        $output = $this->prepareOutput($playlist);

        if ($mode === PlaylistCoverMode::Custom) {
            $custom = $this->customPath($playlistId);
            if (is_file($custom)) {
                $this->publishFile($custom, $output);
            }

            return;
        }

        $title = $this->titleOf($playlist);
        $effective = $mode === PlaylistCoverMode::Auto ? PlaylistCoverMode::Mosaic : $mode;

        if ($effective === PlaylistCoverMode::Mosaic) {
            $this->renderMosaic($playlistId, $title, $output);

            return;
        }

        if ($effective === PlaylistCoverMode::Title) {
            $this->renderTitle($playlistId, $title, $output);

            return;
        }

        $this->renderArtist($playlistId, $title, $output);
    }

    private function renderMosaic(int $playlistId, string $title, string $output): void
    {
        $result = $this->renderer->render([
            'mode' => 'mosaic',
            'title' => $title,
            'playlist_id' => $playlistId,
            'output' => $output,
            'audio_paths' => $this->audioPaths($playlistId),
            'size' => self::SIZE,
        ]);

        if (! $result['ok'] || $result['images'] < 1) {
            $this->renderTitle($playlistId, $title, $output);

            return;
        }

        @chmod($output, 0644);
    }

    private function renderTitle(int $playlistId, string $title, string $output): void
    {
        $this->ensureParent($output);
        $result = $this->renderer->render([
            'mode' => 'title',
            'title' => $title,
            'playlist_id' => $playlistId,
            'output' => $output,
            'size' => self::SIZE,
        ]);

        if (! $result['ok']) {
            throw new RuntimeException($result['reason'] ?? 'title cover failed');
        }

        @chmod($output, 0644);
    }

    private function renderArtist(int $playlistId, string $title, string $output): void
    {
        $rank = $this->ranker->rank($this->artistNames($playlistId));
        $temporary = [];

        try {
            if (is_string($rank['clear']) && $rank['clear'] !== '') {
                $portrait = $this->materializePortrait($rank['clear'], $temporary);
                if ($portrait !== null) {
                    $this->renderImages($playlistId, $title, $output, 'portrait', [$portrait]);

                    return;
                }
            }

            $paths = [];
            foreach ($rank['top'] as $name) {
                $portrait = $this->materializePortrait($name, $temporary);
                if ($portrait !== null) {
                    $paths[] = $portrait;
                }
            }

            if ($paths === []) {
                $this->renderTitle($playlistId, $title, $output);

                return;
            }

            $this->renderImages($playlistId, $title, $output, 'grid', $paths);
        } finally {
            foreach ($temporary as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
        }
    }

    /**
     * @param  list<string>  $imagePaths
     */
    private function renderImages(int $playlistId, string $title, string $output, string $mode, array $imagePaths): void
    {
        $this->ensureParent($output);
        $result = $this->renderer->render([
            'mode' => $mode,
            'title' => $title,
            'playlist_id' => $playlistId,
            'output' => $output,
            'image_paths' => $imagePaths,
            'size' => self::SIZE,
        ]);

        if (! $result['ok']) {
            $this->renderTitle($playlistId, $title, $output);

            return;
        }

        @chmod($output, 0644);
    }

    /**
     * @param  list<string>  $temporary
     */
    private function materializePortrait(string $artistName, array &$temporary): ?string
    {
        $url = $this->portraits->portraitUrl($artistName);
        if (! is_string($url) || $url === '') {
            return null;
        }

        if (str_starts_with($url, 'file://')) {
            $path = substr($url, 7);

            return is_file($path) ? $path : null;
        }

        try {
            $response = Http::timeout(15)->get($url);
        } catch (Throwable $exception) {
            Log::warning('artist portrait download failed', [
                'artist' => $artistName,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $body = $response->body();
        if (! $this->looksLikeImage($body)) {
            return null;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'playlist-cover-');
        if ($tmp === false) {
            return null;
        }

        file_put_contents($tmp, $body);
        $temporary[] = $tmp;

        return $tmp;
    }

    private function looksLikeImage(string $body): bool
    {
        if ($body === '') {
            return false;
        }

        return str_starts_with($body, "\xFF\xD8")
            || str_starts_with($body, "\x89PNG")
            || str_starts_with($body, 'GIF8')
            || (str_starts_with($body, 'RIFF') && str_contains(substr($body, 0, 16), 'WEBP'));
    }

    /**
     * @return list<string>
     */
    private function audioPaths(int $playlistId): array
    {
        $paths = [];
        foreach ($this->playlists->listTracks($playlistId) as $track) {
            $status = (string) ($track['status'] ?? '');
            if (! in_array($status, [
                PlaylistTrackStatus::Downloaded->value,
                PlaylistTrackStatus::Existing->value,
            ], true)) {
                continue;
            }

            $filePath = $track['file_path'] ?? null;
            if (! is_string($filePath) || $filePath === '' || ! is_file($filePath)) {
                continue;
            }

            $paths[] = $filePath;
        }

        return $paths;
    }

    /**
     * @return list<string|null>
     */
    private function artistNames(int $playlistId): array
    {
        $names = [];
        foreach ($this->playlists->listTracks($playlistId) as $track) {
            if ((string) ($track['status'] ?? '') === PlaylistTrackStatus::Skipped->value) {
                continue;
            }

            $artist = $track['artist'] ?? null;
            $names[] = is_string($artist) ? $artist : null;
        }

        return $names;
    }

    /**
     * @param  array<string, mixed>  $playlist
     */
    private function prepareOutput(array $playlist): string
    {
        $output = $this->publishedPath($playlist);
        $this->removeGenericArtwork(dirname($output), $output);

        return $output;
    }

    /**
     * Streamrip saves the last track's album art as cover.jpg in the playlist
     * folder. Navidrome prefers that name over embedded art and over the
     * playlist sidecar, so every song (and the playlist mosaic) shows that one image.
     */
    private function removeGenericArtwork(string $directory, string $keep): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $generic = [
            'cover.jpg', 'cover.jpeg', 'cover.png', 'cover.webp',
            'folder.jpg', 'folder.jpeg', 'folder.png', 'folder.webp',
            'front.jpg', 'front.jpeg', 'front.png',
            'album.jpg', 'albumart.jpg',
        ];
        $keepReal = realpath($keep) ?: $keep;
        $items = scandir($directory);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if (! in_array(strtolower($item), $generic, true)) {
                continue;
            }

            $path = $directory.'/'.$item;
            if (! is_file($path)) {
                continue;
            }

            $real = realpath($path) ?: $path;
            if ($real === $keepReal) {
                continue;
            }

            @unlink($path);
        }
    }

    /**
     * @param  array<string, mixed>  $playlist
     */
    private function publishedPath(array $playlist): string
    {
        $playlistId = (int) $playlist['id'];
        $title = is_string($playlist['title'] ?? null) ? $playlist['title'] : null;
        $directory = $this->storage->playlistDirectory($playlistId, $title, $this->libraryRoot($playlist));
        $this->storage->ensureDirectory($directory);

        return $directory.'/'.$this->storage->playlistFolderName($playlistId, $title).'.jpg';
    }

    /**
     * @param  array<string, mixed>  $playlist
     */
    private function libraryRoot(array $playlist): ?string
    {
        $userId = $playlist['user_id'] ?? null;
        if ($userId === null || $userId === '') {
            return null;
        }

        $owner = User::query()->find((int) $userId);

        return $this->paths->rootForPlaylist($owner instanceof User ? $owner : null);
    }

    /**
     * @param  array<string, mixed>  $playlist
     */
    private function titleOf(array $playlist): string
    {
        $title = $playlist['title'] ?? null;
        if (is_string($title) && trim($title) !== '') {
            return trim($title);
        }

        return 'Playlist '.(int) $playlist['id'];
    }

    private function publishFile(string $source, string $output): void
    {
        $this->ensureParent($output);
        if (! copy($source, $output)) {
            throw new RuntimeException('No se pudo copiar la portada.');
        }

        @chmod($output, 0644);
    }

    private function ensureParent(string $path): void
    {
        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
    }
}
