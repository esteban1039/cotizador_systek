<?php

namespace App\Domain\Quotes;

use RuntimeException;

/**
 * ReteIVA: tarifa aplicable según si el cliente es agente retenedor de IVA.
 * La tarifa vive en configuración (no en base de datos ni editable por
 * interfaz en esta iteración).
 */
final class VatWithholdingPolicy
{
    public static function rateFor(bool $clientWithholds): int
    {
        if (! $clientWithholds) {
            return 0;
        }
        $bps = config('quotes.vat_withholding_bps');
        if (! is_int($bps) || $bps < 0 || $bps > 10000) {
            throw new RuntimeException('La tarifa de ReteIVA configurada (QUOTE_VAT_WITHHOLDING_BPS) no es válida.');
        }

        return $bps;
    }
}
