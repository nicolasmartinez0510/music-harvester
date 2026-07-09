<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin array<string, mixed>
 */
final class SettingsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $settings = is_array($this->resource) ? $this->resource : (array) $this->resource;

        return [
            'music_path' => (string) $settings['music_path'],
            'default_format' => (string) $settings['default_format'],
            'max_concurrency' => (int) $settings['max_concurrency'],
            'cookies_path' => $settings['cookies_path'],
            'cookies_configured' => (bool) $settings['cookies_configured'],
        ];
    }
}
