<?php

declare(strict_types=1);

namespace App\Application\UpdateSavedPlaylist;

use App\Domain\Music\Contracts\SavedPlaylistRepository;
use App\Domain\Music\ValueObjects\AudioFormat;

final readonly class UpdateSavedPlaylistCommand
{
    /**
     * @param  array{sync_enabled?: bool, sync_interval_minutes?: int, default_format?: string|null}  $attributes
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

        return $this->playlists->update($command->id, $attributes);
    }
}
