<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Music\ValueObjects\CatalogType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SearchCatalogRequest extends FormRequest
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
            'provider' => ['required', 'string', 'max:64'],
            'q' => ['required', 'string', 'max:500'],
            'type' => ['sometimes', 'string', Rule::in(array_column(CatalogType::cases(), 'value'))],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'index' => ['sometimes', 'integer', 'min:0', 'max:10000'],
        ];
    }
}
