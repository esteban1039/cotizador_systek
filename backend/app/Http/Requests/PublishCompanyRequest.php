<?php

namespace App\Http\Requests;

use App\Domain\Nit;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class PublishCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'legal_name' => ['required', 'string', 'max:200'],
            'trade_name' => ['nullable', 'string', 'max:100'],
            'nit' => ['nullable', 'string', 'max:20', function (string $attribute, mixed $value, Closure $fail): void {
                if ($value !== null && $value !== '' && Nit::normalize($value) === null) {
                    $fail('El dígito de verificación del NIT no es válido.');
                }
            }],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+() -]{7,40}$/'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'website' => ['nullable', 'url:https', 'max:255'],
            'signer_name' => ['nullable', 'string', 'max:150'],
            'signer_title' => ['nullable', 'string', 'max:150'],
            'bank_account' => ['nullable', 'array', 'required_array_keys:bank_name,account_type,account_number,account_number_confirmation'],
            'bank_account.bank_name' => ['required_with:bank_account', 'string', 'min:2', 'max:100'],
            'bank_account.account_type' => ['required_with:bank_account', Rule::in(['savings', 'checking'])],
            'bank_account.account_number' => ['required_with:bank_account', 'regex:/^[0-9][0-9 -]{3,28}[0-9]$/', 'confirmed'],
            'bank_account.account_number_confirmation' => ['required_with:bank_account', 'string'],
            'bank_account.account_holder' => ['nullable', 'string', 'max:200'],
            'clear_bank_account' => ['sometimes', 'boolean'],
            'emission_requires_authorization' => ['sometimes', 'boolean'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->boolean('clear_bank_account') && $this->filled('bank_account')) {
                $validator->errors()->add('clear_bank_account', 'No puedes enviar una cuenta nueva y pedir eliminarla en la misma solicitud.');
            }
            $accountNumber = $this->input('bank_account.account_number');
            if (is_string($accountNumber)) {
                $digits = preg_replace('/\D/', '', $accountNumber) ?? '';
                if (strlen($digits) < 6 || strlen($digits) > 20) {
                    $validator->errors()->add('bank_account.account_number', 'El número de cuenta debe tener entre 6 y 20 dígitos.');
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'required' => 'El campo :attribute es obligatorio.',
            'required_with' => 'El campo :attribute es obligatorio.',
            'confirmed' => 'La confirmación del número de cuenta no coincide.',
            'url' => 'El sitio web debe ser una URL https válida.',
            'email' => 'El correo no es válido.',
        ];
    }

    public function attributes(): array
    {
        return [
            'legal_name' => 'razón social', 'trade_name' => 'nombre comercial', 'nit' => 'NIT',
            'address' => 'dirección', 'phone' => 'teléfono', 'email' => 'correo', 'website' => 'sitio web',
            'signer_name' => 'nombre del firmante', 'signer_title' => 'cargo del firmante',
            'bank_account.bank_name' => 'banco', 'bank_account.account_type' => 'tipo de cuenta',
            'bank_account.account_number' => 'número de cuenta', 'bank_account.account_holder' => 'titular',
            'emission_requires_authorization' => 'autorización de emisión',
            'reason' => 'motivo',
        ];
    }
}
