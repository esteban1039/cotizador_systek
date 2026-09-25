<?php

namespace App\Repositories\Contracts;

interface CompanyRepository
{
    /**
     * Versión vigente, sin la cuenta bancaria en claro: agrega
     * `bank_account_configured` y `bank_account_summary` (enmascarado).
     * Null si no hay ninguna versión.
     *
     * @return array<string, mixed>|null
     */
    public function currentProfile(): ?array;

    /**
     * Dentro de transacción: crea (insertOrIgnore) y bloquea la fila de la
     * empresa; devuelve la versión vigente completa, con `bank_account`
     * descifrado, solo para uso interno del caso de uso.
     *
     * @return array<string, mixed>|null
     */
    public function lockCurrentForUpdate(): ?array;

    /**
     * Tras `lockCurrentForUpdate`: marca histórica la vigente e inserta la
     * siguiente versión. Devuelve el perfil público.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function appendVersion(array $attributes): array;

    /**
     * Dentro de transacción, al final de la cadena de bloqueos de emisión:
     * bloqueo compartido de la empresa (espera a `PublishCompanyProfile`) y
     * versión vigente completa con `bank_account` descifrado, solo para el
     * caso de uso. No crea filas. Null si no hay versión.
     *
     * @return array<string, mixed>|null
     */
    public function lockedCurrentForEmission(): ?array;

    public function hasAnyVersion(): bool;
}
