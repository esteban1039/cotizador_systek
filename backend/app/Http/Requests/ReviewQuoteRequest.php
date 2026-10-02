<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ReviewQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['approve', 'return'])],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'confirm_new_items' => ['sometimes', 'array', 'max:20'],
            'confirm_new_items.*' => ['uuid', 'distinct'],
        ];
    }
}
