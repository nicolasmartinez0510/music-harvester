<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StorePlaylistRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'url' => ['required', 'string', 'url', 'max:2048'],
            'sync_now' => ['sometimes', 'boolean'],
            'sync_enabled' => ['sometimes', 'boolean'],
            'sync_interval_minutes' => ['sometimes', 'integer', 'min:1', 'max:10080'],
        ];
    }
}
