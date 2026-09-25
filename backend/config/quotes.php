<?php

return [

    /*
    |--------------------------------------------------------------------------
    | ReteIVA (retención en la fuente sobre IVA)
    |--------------------------------------------------------------------------
    |
    | Tarifa en puntos básicos (10000 = 100 %) aplicada sobre el IVA total de
    | la cotización cuando el cliente es agente retenedor de IVA
    | (`clients.withholds_vat`). Por defecto 1500 = 15 %.
    | Ver App\Domain\Quotes\VatWithholdingPolicy.
    |
    */

    'vat_withholding_bps' => (int) env('QUOTE_VAT_WITHHOLDING_BPS', 1500),

];
