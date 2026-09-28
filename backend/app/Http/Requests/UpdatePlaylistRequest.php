<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Music\ValueObjects\AudioFormat;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdatePlaylistRequest extends FormRequest
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
            'sync_enabled' => ['sometimes', 'boolean'],
            'sync_interval_minutes' => ['sometimes', 'integer', 'min:1', 'max:10080'],
            'default_format' => ['sometimes', 'nullable', 'string', Rule::enum(AudioFormat::class)],
        ];
    }
}
