<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Music\ValueObjects\AudioFormat;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin array<string, mixed>
 */
final class DownloadJobResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $job = is_array($this->resource) ? $this->resource : (array) $this->resource;

        return [
            'id' => (int) $job['id'],
            'provider' => (string) $job['provider'],
            'url' => (string) $job['url'],
            'kind' => (string) $job['kind'],
            'status' => (string) $job['status'],
            'progress' => (int) $job['progress'],
            'error' => $job['error'],
            'destination_path' => $job['destination_path'],
            'format' => $this->resolveFormat($job),
            'created_at' => $job['created_at'],
            'updated_at' => $job['updated_at'],
        ];
    }

    /**
     * @param  array<string, mixed>  $job
     */
    private function resolveFormat(array $job): string
    {
        $optionsJson = json_decode((string) ($job['options_json'] ?? '{}'), true);
        $formatValue = is_array($optionsJson) ? ($optionsJson['format'] ?? null) : null;
        $format = AudioFormat::tryFrom((string) ($formatValue ?? config('music.default_format')));

        return ($format ?? AudioFormat::Mp3_320)->value;
    }
}
