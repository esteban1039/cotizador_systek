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

    /*
    |--------------------------------------------------------------------------
    | Almacenamiento del PDF oficial emitido
    |--------------------------------------------------------------------------
    |
    | `database` (por defecto): cifrado en `quote_emission_files.content`.
    | `s3`: objeto en el disco `official_pdfs` (bucket privado). Los PDF ya
    | archivados en la base se siguen sirviendo desde allí.
    |
    */

    'official_pdf_storage' => in_array(env('QUOTE_PDF_STORAGE', 'database'), ['database', 's3'], true) ? env('QUOTE_PDF_STORAGE', 'database') : 'database',

];
