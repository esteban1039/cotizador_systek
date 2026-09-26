<?php

namespace App\Http\Requests;

use App\Domain\Quotes\ClauseText;
use App\Domain\Quotes\FollowupPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RecordQuoteFollowupRequest extends FormRequest
{
    /** El rol se comprueba antes de validar; la autoría, en el caso de uso. */
    public function authorize(): bool
    {
        return in_array($this->user()?->role, ['admin', 'quoter'], true);
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(FollowupPolicy::TYPES)],
            'channel' => ['required_if:type,sent', 'prohibited_unless:type,sent', 'nullable', 'string', Rule::in(FollowupPolicy::CHANNELS)],
            'occurred_at' => ['required', 'date'],
            'note' => [
                'required_if:type,rejected', 'nullable', 'string', 'max:1000',
                fn (string $attribute, mixed $value, \Closure $fail) => is_string($value) && ClauseText::looksLikeBankAccount($value)
                    ? $fail('La nota no puede contener secuencias que parezcan números de cuenta.') : null,
            ],
        ];
    }

    public function attributes(): array
    {
        return ['type' => 'tipo', 'channel' => 'canal', 'occurred_at' => 'fecha del evento', 'note' => 'nota'];
    }
}
