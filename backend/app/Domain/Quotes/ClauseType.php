<?php

namespace App\Domain\Quotes;

/**
 * Tipos de cláusula por familia. Cada tipo mapea a un campo de la
 * instantánea de la cotización, con su longitud máxima y si es obligatoria
 * para aprobar (bloquea M1 en `ClauseCoherence`).
 */
enum ClauseType: string
{
    case ScopeBase = 'scope_base';
    case Exclusions = 'exclusions';
    case Payment = 'payment';
    case Warranty = 'warranty';
    case Validity = 'validity';
    case Observations = 'observations';

    public function snapshotField(): string
    {
        return match ($this) {
            self::ScopeBase => 'scope',
            self::Exclusions => 'exclusions',
            self::Payment => 'payment_terms',
            self::Warranty => 'warranty',
            self::Validity => 'validity_terms',
            self::Observations => 'observations',
        };
    }

    public function maxLength(): int
    {
        return match ($this) {
            self::ScopeBase, self::Exclusions, self::Observations => 5000,
            self::Payment, self::Warranty, self::Validity => 1000,
        };
    }

    public function requiredForApproval(): bool
    {
        return match ($this) {
            self::Payment, self::Warranty, self::Validity => true,
            self::ScopeBase, self::Exclusions, self::Observations => false,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $type): string => $type->value, self::cases());
    }
}
