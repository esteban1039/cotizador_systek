<?php

namespace App\Application\Company;

use App\Domain\Audit;
use App\Domain\Nit;
use App\Models\User;
use App\Repositories\Contracts\CompanyRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PublishCompanyProfile
{
    /** @var list<string> */
    private const PUBLIC_FIELDS = [
        'legal_name', 'trade_name', 'nit', 'address', 'phone', 'email', 'website', 'signer_name', 'signer_title',
    ];

    public function __construct(private CompanyRepository $companies) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function execute(array $input, ?User $actor, string $origin = 'admin'): array
    {
        return DB::transaction(function () use ($input, $actor, $origin) {
            $current = $this->companies->lockCurrentForUpdate();

            $nit = null;
            if (! empty($input['nit'] ?? null)) {
                $nit = Nit::normalize($input['nit']);
                if ($nit === null) {
                    throw ValidationException::withMessages(['nit' => 'El dígito de verificación del NIT no es válido.']);
                }
            }

            $bankAccount = $this->resolveBankAccount($input, $current);
            $bankAccountAction = $this->bankAccountAction($current, $bankAccount);

            $attributes = [
                'legal_name' => $input['legal_name'],
                'trade_name' => $input['trade_name'] ?? null,
                'nit' => $nit,
                'address' => $input['address'] ?? null,
                'phone' => $input['phone'] ?? null,
                'email' => $input['email'] ?? null,
                'website' => $input['website'] ?? null,
                'signer_name' => $input['signer_name'] ?? null,
                'signer_title' => $input['signer_title'] ?? null,
                'bank_account' => $bankAccount,
                'origin' => $origin,
                'reason' => $input['reason'],
                'published_by' => $actor?->id,
            ];

            $changedFields = $this->changedFields($current, $attributes);

            $profile = $this->companies->appendVersion($attributes);

            $details = [
                'version' => $profile['version'], 'origin' => $origin,
                'changed_fields' => $changedFields, 'reason' => $input['reason'],
            ];
            if ($bankAccountAction !== null) {
                $details['bank_account'] = $bankAccountAction === 'updated' ? 'Datos bancarios actualizados' : 'Datos bancarios eliminados';
            }
            Audit::record($actor?->id, 'company.published', $profile['id'], $details);

            return $profile;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>|null  $current
     * @return array<string, mixed>|null
     */
    private function resolveBankAccount(array $input, ?array $current): ?array
    {
        if (($input['clear_bank_account'] ?? false) === true) {
            return null;
        }
        if (array_key_exists('bank_account', $input) && $input['bank_account'] !== null) {
            return [
                'bank_name' => $input['bank_account']['bank_name'],
                'account_type' => $input['bank_account']['account_type'],
                'account_number' => preg_replace('/[\s-]/', '', (string) $input['bank_account']['account_number']),
                'account_holder' => $input['bank_account']['account_holder'] ?? null,
            ];
        }

        return $current['bank_account'] ?? null;
    }

    /**
     * @param  array<string, mixed>|null  $current
     * @param  array<string, mixed>|null  $new
     */
    private function bankAccountAction(?array $current, ?array $new): ?string
    {
        $had = ($current['bank_account'] ?? null) !== null;
        $has = $new !== null;
        if (! $had && ! $has) {
            return null;
        }
        if ($had && ! $has) {
            return 'removed';
        }
        if (! $had && $has) {
            return 'updated';
        }

        return ($current['bank_account'] ?? null) !== $new ? 'updated' : null;
    }

    /**
     * @param  array<string, mixed>|null  $current
     * @param  array<string, mixed>  $attributes
     * @return list<string>
     */
    private function changedFields(?array $current, array $attributes): array
    {
        $changed = [];
        foreach (self::PUBLIC_FIELDS as $field) {
            $before = $current[$field] ?? null;
            if ($before !== ($attributes[$field] ?? null)) {
                $changed[] = $field;
            }
        }

        return $changed;
    }
}
