<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\CompanyVersion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CompanyVersion>
 */
class CompanyVersionFactory extends Factory
{
    protected $model = CompanyVersion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'company_id' => Company::factory(),
            'version' => 1,
            'status' => 'current',
            'legal_name' => fake()->company().' S.A.S.',
            'trade_name' => null,
            'nit' => null,
            'address' => fake()->address(),
            'phone' => fake()->numerify('300#######'),
            'email' => fake()->companyEmail(),
            'website' => 'https://'.fake()->domainName(),
            'signer_name' => fake()->name(),
            'signer_title' => 'Gerente',
            'bank_account' => null,
            'origin' => 'admin',
            'reason' => 'Datos de prueba generados por la factory',
            'published_by' => null,
        ];
    }

    /**
     * Cuenta bancaria ficticia (nunca un número real), para pruebas de cifrado
     * y de enmascaramiento del resumen.
     */
    public function withFakeBankAccount(): static
    {
        return $this->state(fn (): array => [
            'bank_account' => [
                'bank_name' => 'Banco Ficticio de Pruebas',
                'account_type' => 'savings',
                'account_number' => fake()->numerify('##########'),
                'account_holder' => null,
            ],
        ]);
    }

    public function historical(): static
    {
        return $this->state(fn (): array => ['status' => 'historical']);
    }
}
