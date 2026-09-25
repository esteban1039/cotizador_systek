<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PreviewQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_id' => ['sometimes', 'nullable', 'uuid', 'exists:clients,id'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*' => ['required', 'array:price_version_id,quantity,discount_bps'],
            'lines.*.price_version_id' => ['required', 'uuid', 'exists:price_versions,id'],
            'lines.*.quantity' => ['required', 'string', 'regex:/^\d{1,5}(\.\d{1,3})?$/D', 'not_in:0,0.0,0.00,0.000'],
            'lines.*.discount_bps' => ['required', 'integer', 'between:0,10000'],
        ];
    }

    public function messages(): array
    {
        return [
            'required' => 'El campo :attribute es obligatorio.',
            'exists' => 'La selección de :attribute no es válida.',
            'uuid' => 'La referencia de :attribute no es válida.',
            'string' => 'El campo :attribute debe ser texto.',
            'integer' => 'El campo :attribute debe ser un número entero.',
            'between' => 'El campo :attribute debe estar entre :min y :max.',
            'max' => 'El campo :attribute supera el máximo permitido (:max).',
            'min' => 'El campo :attribute debe incluir al menos :min elemento.',
            'array' => 'Revisa los campos permitidos en :attribute.',
            'regex' => 'La cantidad debe ser positiva y tener hasta tres decimales.',
            'not_in' => 'La cantidad debe ser mayor que cero.',
        ];
    }

    public function attributes(): array
    {
        return [
            'client_id' => 'cliente', 'site_id' => 'sede', 'lines' => 'partidas',
            'lines.*.price_version_id' => 'precio', 'lines.*.quantity' => 'cantidad',
            'lines.*.discount_bps' => 'descuento', 'scope' => 'alcance',
            'exclusions' => 'exclusiones', 'payment_terms' => 'forma de pago',
            'warranty' => 'garantía', 'validity_days' => 'vigencia (días)',
            'family' => 'familia', 'validity_terms' => 'vigencia', 'observations' => 'observaciones',
        ];
    }
}
