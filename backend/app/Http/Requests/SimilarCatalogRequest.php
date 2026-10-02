<?php

namespace App\Http\Requests;

use App\Domain\QuoteFamily;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SimilarCatalogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:3', 'max:120'],
            'family' => ['nullable', Rule::in(QuoteFamily::values())],
        ];
    }
}
