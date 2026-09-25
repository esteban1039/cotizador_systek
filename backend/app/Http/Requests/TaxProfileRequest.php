<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class TaxProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'withholds_vat' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }

    public function attributes(): array
    {
        return ['withholds_vat' => 'agente retenedor de IVA', 'reason' => 'motivo'];
    }
}
