<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin array<string, mixed>
 */
final class SavedPlaylistResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $playlist = is_array($this->resource) ? $this->resource : (array) $this->resource;
        $counts = is_array($playlist['counts'] ?? null) ? $playlist['counts'] : null;

        return [
            'id' => (int) $playlist['id'],
            'provider' => (string) $playlist['provider'],
            'url' => (string) $playlist['url'],
            'title' => $playlist['title'],
            'sync_enabled' => (bool) $playlist['sync_enabled'],
            'sync_interval_minutes' => (int) $playlist['sync_interval_minutes'],
            'default_format' => $playlist['default_format'],
            'last_synced_at' => $playlist['last_synced_at'],
            'last_sync_status' => (string) $playlist['last_sync_status'],
            'last_sync_error' => $playlist['last_sync_error'],
            'counts' => $counts ?? [
                'total' => 0,
                'downloaded' => 0,
                'pending' => 0,
                'failed' => 0,
                'skipped' => 0,
            ],
            'created_at' => $playlist['created_at'],
            'updated_at' => $playlist['updated_at'],
        ];
    }
}
