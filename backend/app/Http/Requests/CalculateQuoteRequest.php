<?php

namespace App\Http\Requests;

use App\Domain\QuoteFamily;
use Illuminate\Validation\Rule;

final class CalculateQuoteRequest extends PreviewQuoteRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'client_id' => ['required', 'uuid', 'exists:clients,id'],
            'site_id' => ['required', 'uuid', Rule::exists('sites', 'id')->where('client_id', $this->input('client_id'))],
            'assist_request_id' => ['nullable', 'string', 'max:64'],
            'family' => ['required', Rule::in(QuoteFamily::values())],
            'scope' => ['required', 'string', 'max:5000'],
            'exclusions' => ['required', 'string', 'max:5000'],
            'payment_terms' => ['required', 'string', 'max:1000'],
            'warranty' => ['required', 'string', 'max:1000'],
            'validity_terms' => ['required', 'string', 'max:1000'],
            'observations' => ['nullable', 'string', 'max:5000'],
            'validity_days' => ['required', 'integer', 'between:1,90'],
            'clause_versions' => ['sometimes', 'array:scope_base,exclusions,payment,warranty,validity,observations'],
            'clause_versions.scope_base' => ['nullable', 'uuid', 'exists:clause_versions,id'],
            'clause_versions.exclusions' => ['nullable', 'uuid', 'exists:clause_versions,id'],
            'clause_versions.payment' => ['nullable', 'uuid', 'exists:clause_versions,id'],
            'clause_versions.warranty' => ['nullable', 'uuid', 'exists:clause_versions,id'],
            'clause_versions.validity' => ['nullable', 'uuid', 'exists:clause_versions,id'],
            'clause_versions.observations' => ['nullable', 'uuid', 'exists:clause_versions,id'],
        ]);
    }
}
