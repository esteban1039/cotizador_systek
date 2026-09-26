<?php

namespace App\Http\Requests;

use App\Domain\QuoteFamily;
use App\Domain\Quotes\ClauseText;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AssistQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->role, ['admin', 'quoter'], true);
    }

    public function rules(): array
    {
        return [
            'text' => [
                'required', 'string', 'min:10', 'max:'.(int) config('ai_assistant.max_input_chars'),
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value)) {
                        return;
                    }
                    if (ClauseText::looksLikeBankAccount($value)) {
                        $fail('El texto no puede contener secuencias que parezcan números de cuenta, NIT o teléfonos.');
                    } elseif (preg_match('/[^\s@]+@[^\s@]+\.[^\s@]+/u', $value)) {
                        $fail('El texto no puede contener correos electrónicos.');
                    }
                },
            ],
            'family' => ['nullable', 'string', Rule::in(QuoteFamily::values())],
        ];
    }

    public function attributes(): array
    {
        return ['text' => 'texto', 'family' => 'familia'];
    }
}
