<?php

declare(strict_types=1);

namespace App\Domain\Music\Contracts;

interface PlaylistCoverRenderer
{
    /**
     * @param  array{
     *     mode: string,
     *     title: string,
     *     playlist_id: int,
     *     output: string,
     *     audio_paths?: list<string>,
     *     image_paths?: list<string>,
     *     size?: int
     * }  $spec
     * @return array{ok: bool, images: int, reason: string|null}
     */
    public function render(array $spec): array;
}
