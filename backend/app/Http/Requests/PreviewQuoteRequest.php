<?php

namespace App\Http\Requests;

use App\Domain\Quotes\QuotePricer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PreviewQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'client_id' => ['sometimes', 'nullable', 'uuid', 'exists:clients,id'],
            'site_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('sites', 'id')->where('client_id', $this->input('client_id'))],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
        ];
        $quantity = ['required', 'string', 'regex:/^\d{1,5}(\.\d{1,3})?$/D', 'not_in:0,0.0,0.00,0.000'];
        $lines = $this->input('lines');
        foreach (is_array($lines) ? array_keys($lines) : [] as $index) {
            $prefix = "lines.{$index}";
            if (is_array($lines[$index]) && ($lines[$index]['type'] ?? null) === 'free') {
                $rules[$prefix] = ['required', 'array:type,description,unit,quantity,price,cost,tax_bps,discount_bps,confirmed_new'];
                $rules["{$prefix}.type"] = ['required', 'in:free'];
                $rules["{$prefix}.description"] = ['required', 'string', 'min:5', 'max:255', 'regex:/^[^\x00-\x1F\x7F]+$/u'];
                $rules["{$prefix}.unit"] = ['required', Rule::in(QuotePricer::FREE_UNITS)];
                $rules["{$prefix}.quantity"] = $quantity;
                $rules["{$prefix}.price"] = ['required', 'string', 'regex:/^\d{1,16}(\.\d{1,2})?$/D', 'not_in:0,0.0,0.00'];
                $rules["{$prefix}.cost"] = ['required', 'string', 'regex:/^\d{1,16}(\.\d{1,2})?$/D'];
                $rules["{$prefix}.tax_bps"] = ['required', 'integer', Rule::in(QuotePricer::FREE_TAX_BPS)];
                $rules["{$prefix}.discount_bps"] = ['required', 'integer', 'between:0,10000'];
                $rules["{$prefix}.confirmed_new"] = ['accepted'];

                continue;
            }
            $rules[$prefix] = ['required', 'array:price_version_id,quantity,discount_bps'];
            $rules["{$prefix}.price_version_id"] = ['required', 'uuid', 'exists:price_versions,id'];
            $rules["{$prefix}.quantity"] = $quantity;
            $rules["{$prefix}.discount_bps"] = ['required', 'integer', 'between:0,10000'];
        }

        return $rules;
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
            'lines.*.description.regex' => 'La descripción no admite saltos de línea ni caracteres de control.',
            'lines.*.price.regex' => 'Usa un valor en COP sin separadores de miles y con máximo dos decimales.',
            'lines.*.price.not_in' => 'El precio debe ser mayor que cero.',
            'lines.*.cost.regex' => 'Usa un valor en COP sin separadores de miles y con máximo dos decimales.',
            'lines.*.tax_bps.in' => 'El IVA debe ser 0 %, 5 % o 19 %.',
            'lines.*.confirmed_new.accepted' => 'Confirma que la línea libre es un ítem nuevo.',
        ];
    }

    public function attributes(): array
    {
        return [
            'client_id' => 'cliente', 'site_id' => 'sede', 'lines' => 'partidas',
            'lines.*.price_version_id' => 'precio', 'lines.*.quantity' => 'cantidad',
            'lines.*.discount_bps' => 'descuento', 'lines.*.description' => 'descripción', 'lines.*.unit' => 'unidad', 'lines.*.price' => 'precio', 'lines.*.cost' => 'costo', 'lines.*.tax_bps' => 'IVA', 'scope' => 'alcance',
            'exclusions' => 'exclusiones', 'payment_terms' => 'forma de pago',
            'warranty' => 'garantía', 'validity_days' => 'vigencia (días)',
            'family' => 'familia', 'validity_terms' => 'vigencia', 'observations' => 'observaciones',
        ];
    }
}
