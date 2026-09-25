<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class IssueQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:5', 'max:2000']];
    }

    public function attributes(): array
    {
        return ['reason' => 'motivo'];
    }
}
