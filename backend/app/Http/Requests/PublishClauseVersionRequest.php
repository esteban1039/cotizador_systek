<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class PublishClauseVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:5000'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }

    public function attributes(): array
    {
        return ['body' => 'texto', 'reason' => 'motivo'];
    }
}
