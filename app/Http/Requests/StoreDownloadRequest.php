<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Music\ValueObjects\AudioFormat;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreDownloadRequest extends FormRequest
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
            'format' => ['sometimes', 'string', Rule::enum(AudioFormat::class)],
        ];
    }
}
