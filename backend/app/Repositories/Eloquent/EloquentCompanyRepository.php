<?php

namespace App\Repositories\Eloquent;

use App\Models\Company;
use App\Models\CompanyVersion;
use App\Repositories\Contracts\CompanyRepository;
use Illuminate\Support\Str;

final class EloquentCompanyRepository implements CompanyRepository
{
    private const CODE = 'issuer';

    public function currentProfile(): ?array
    {
        $version = $this->currentVersion();

        return $version ? $this->publicProfile($version) : null;
    }

    public function hasAnyVersion(): bool
    {
        return CompanyVersion::query()->exists();
    }

    public function lockCurrentForUpdate(): ?array
    {
        $company = $this->lockedCompany();
        $version = CompanyVersion::query()->where('company_id', $company->id)->where('status', 'current')->lockForUpdate()->first();

        return $version ? $this->internalArray($version) : null;
    }

    public function appendVersion(array $attributes): array
    {
        $company = $this->lockedCompany();
        $current = CompanyVersion::query()->where('company_id', $company->id)->where('status', 'current')->lockForUpdate()->first();
        $nextVersion = $current ? $current->version + 1 : 1;
        if ($current) {
            $current->update(['status' => 'historical']);
        }
        $version = CompanyVersion::query()->create(array_merge($attributes, [
            'id' => (string) Str::uuid(),
            'company_id' => $company->id,
            'version' => $nextVersion,
            'status' => 'current',
        ]));

        return $this->publicProfile($version);
    }

    private function lockedCompany(): Company
    {
        $company = Company::query()->where('code', self::CODE)->lockForUpdate()->first();
        if ($company) {
            return $company;
        }
        Company::query()->insertOrIgnore([
            'id' => (string) Str::uuid(), 'code' => self::CODE, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return Company::query()->where('code', self::CODE)->lockForUpdate()->firstOrFail();
    }

    private function currentVersion(): ?CompanyVersion
    {
        $company = Company::query()->where('code', self::CODE)->first();
        if (! $company) {
            return null;
        }

        return CompanyVersion::query()->where('company_id', $company->id)->where('status', 'current')->first();
    }

    /**
     * Representación interna con `bank_account` descifrado; solo para el
     * caso de uso que publica una versión nueva (nunca sale por la API).
     *
     * @return array<string, mixed>
     */
    private function internalArray(CompanyVersion $version): array
    {
        return [
            'id' => $version->id,
            'version' => $version->version,
            'origin' => $version->origin,
            'legal_name' => $version->legal_name,
            'trade_name' => $version->trade_name,
            'nit' => $version->nit,
            'address' => $version->address,
            'phone' => $version->phone,
            'email' => $version->email,
            'website' => $version->website,
            'signer_name' => $version->signer_name,
            'signer_title' => $version->signer_title,
            'bank_account' => $version->bank_account,
        ];
    }

    /**
     * Representación pública: nunca incluye la cuenta bancaria en claro,
     * solo el resumen enmascarado.
     *
     * @return array<string, mixed>
     */
    private function publicProfile(CompanyVersion $version): array
    {
        $bankAccount = $version->bank_account;
        $configured = $bankAccount !== null;
        $summary = null;
        if ($configured) {
            $digits = preg_replace('/\D/', '', (string) ($bankAccount['account_number'] ?? '')) ?? '';
            $summary = [
                'bank_name' => $bankAccount['bank_name'] ?? null,
                'account_type' => $bankAccount['account_type'] ?? null,
                'account_number_masked' => '••••'.substr($digits, -4),
                'has_holder' => ! empty($bankAccount['account_holder']),
            ];
        }
        $version->loadMissing('publisher');

        return [
            'id' => $version->id,
            'version' => $version->version,
            'origin' => $version->origin,
            'legal_name' => $version->legal_name,
            'trade_name' => $version->trade_name,
            'nit' => $version->nit,
            'address' => $version->address,
            'phone' => $version->phone,
            'email' => $version->email,
            'website' => $version->website,
            'signer_name' => $version->signer_name,
            'signer_title' => $version->signer_title,
            'bank_account_configured' => $configured,
            'bank_account_summary' => $summary,
            'updated_at' => $version->updated_at?->toIso8601String(),
            'updated_by_name' => $version->publisher?->name,
        ];
    }
}
