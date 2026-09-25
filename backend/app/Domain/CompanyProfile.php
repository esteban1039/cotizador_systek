<?php

namespace App\Domain;

/**
 * Completitud del perfil de la empresa emisora, para la futura emisión
 * oficial (esta iteración no habilita emitir).
 */
final class CompanyProfile
{
    /** @var list<string> */
    private const REQUIRED_FIELDS = [
        'legal_name', 'nit', 'address', 'phone', 'email', 'signer_name', 'signer_title', 'bank_account',
    ];

    /**
     * @param  array<string, mixed>|null  $profile  Debe incluir la clave `bank_account`
     *                                              como valor truthy/falsy (configurada o no).
     * @return list<string>
     */
    public static function missing(?array $profile): array
    {
        if ($profile === null) {
            return self::REQUIRED_FIELDS;
        }

        return array_values(array_filter(
            self::REQUIRED_FIELDS,
            fn (string $field): bool => empty($profile[$field] ?? null)
        ));
    }
}
