<?php

declare(strict_types=1);

namespace App\Application\UpdateSavedPlaylist;

use App\Application\PlaylistCover\PlaylistCoverGenerator;
use App\Domain\Music\Contracts\SavedPlaylistRepository;
use App\Domain\Music\ValueObjects\AudioFormat;
use App\Domain\Music\ValueObjects\PlaylistCoverMode;

final readonly class UpdateSavedPlaylistCommand
{
    /**
     * @param  array{sync_enabled?: bool, sync_interval_minutes?: int, default_format?: string|null, cover_mode?: string}  $attributes
     */
    public function __construct(
        public int $id,
        public array $attributes,
    ) {}
}

final readonly class UpdateSavedPlaylistHandler
{
    public function __construct(
        private SavedPlaylistRepository $playlists,
        private PlaylistCoverGenerator $covers,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function handle(UpdateSavedPlaylistCommand $command): ?array
    {
        $attributes = $command->attributes;

        if (array_key_exists('default_format', $attributes) && $attributes['default_format'] !== null) {
            $format = AudioFormat::tryFrom((string) $attributes['default_format']);

            if ($format === null) {
                throw new \ValueError('Invalid default_format: '.$attributes['default_format']);
            }

            $attributes['default_format'] = $format->value;
        }

        if (
            array_key_exists('cover_mode', $attributes)
            && $attributes['cover_mode'] === PlaylistCoverMode::Custom->value
            && ! $this->covers->hasCustom($command->id)
        ) {
            throw new \InvalidArgumentException('Subí una imagen para usar como portada.');
        }

        $playlist = $this->playlists->update($command->id, $attributes);

        if ($playlist !== null && array_key_exists('cover_mode', $command->attributes)) {
            $this->covers->generate($command->id);
        }

        return $playlist;
    }
}
