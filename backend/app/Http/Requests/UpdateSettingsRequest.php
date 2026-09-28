<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Music\ValueObjects\AudioFormat;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateSettingsRequest extends FormRequest
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
            'music_path' => ['sometimes', 'string', 'max:500'],
            'default_format' => ['sometimes', 'string', Rule::enum(AudioFormat::class)],
            'max_concurrency' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'enabled_providers' => ['sometimes', 'string', 'max:200'],
            'provider_youtube_music_cookies_path' => ['sometimes', 'nullable', 'string', 'max:500'],
            'provider_deezer_arl' => ['sometimes', 'nullable', 'string', 'max:512'],
            'provider_deezer_mode' => ['sometimes', 'string', Rule::in(['native', 'hybrid'])],
            'cookies_path' => ['sometimes', 'nullable', 'string', 'max:500'],
            'email_verification_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
