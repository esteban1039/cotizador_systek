<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ReviewKnowledgeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::in(['active', 'excluded'])],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'requirement_text' => ['sometimes', 'string', 'min:3', 'max:2000'],
            'scope' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'exclusions' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }

    public function after(): array
    {
        return [fn (Validator $v) => $this->hasAny(['status', 'requirement_text', 'scope', 'exclusions']) ? null : $v->errors()->add('status', 'Indica un estado o un texto a editar.')];
    }

    /** @return array<string, mixed> */
    public function review(): array
    {
        return $this->safe()->only(['status', 'reason', 'requirement_text', 'scope', 'exclusions']);
    }
}
