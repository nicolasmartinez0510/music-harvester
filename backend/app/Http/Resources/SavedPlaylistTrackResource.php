<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin array<string, mixed>
 */
final class SavedPlaylistTrackResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $track = is_array($this->resource) ? $this->resource : (array) $this->resource;

        return [
            'id' => (int) $track['id'],
            'external_id' => (string) $track['external_id'],
            'title' => (string) $track['title'],
            'artist' => $track['artist'],
            'position' => (int) $track['position'],
            'status' => (string) $track['status'],
            'file_path' => $track['file_path'],
            'last_error' => $track['last_error'],
            'first_seen_at' => $track['first_seen_at'],
            'downloaded_at' => $track['downloaded_at'],
        ];
    }
}
