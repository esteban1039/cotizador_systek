<?php

namespace App\Http\Requests;

use App\Domain\QuoteFamily;
use App\Domain\Quotes\ClauseType;
use App\Repositories\Contracts\ClauseRepository;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreateClauseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'family' => ['required', Rule::in(QuoteFamily::values())],
            'type' => ['required', Rule::in(ClauseType::values())],
            'title' => ['required', 'string', 'min:3', 'max:120', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($this->input('family')) || ! is_string($this->input('type'))) {
                    return;
                }
                if (app(ClauseRepository::class)->titleExists($this->input('family'), $this->input('type'), $value)) {
                    $fail('Ya existe una cláusula con este título para la familia y el tipo seleccionados.');
                }
            }],
            'body' => ['required', 'string'],
            'is_default' => ['sometimes', 'boolean'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }

    public function attributes(): array
    {
        return ['family' => 'familia', 'type' => 'tipo', 'title' => 'título', 'body' => 'texto', 'reason' => 'motivo'];
    }
}
