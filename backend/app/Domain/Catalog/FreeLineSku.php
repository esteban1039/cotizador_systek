<?php

namespace App\Domain\Catalog;

/**
 * SKU de los ítems creados desde líneas libres: prefijo de familia + "-L" + hex del
 * identificador de la línea. Puro; la unicidad final la protege el índice único.
 */
final class FreeLineSku
{
    private const PREFIXES = [
        'cctv' => 'CCTV', 'data_power' => 'DATA', 'equipment' => 'EQP', 'software' => 'SW',
        'ups' => 'UPS', 'security' => 'SEG', 'services' => 'SRV',
    ];

    /** $hexLength crece (8..32) cuando el SKU ya existe. */
    public static function for(string $family, string $freeLineId, int $hexLength = 8): string
    {
        $hex = strtoupper(str_replace('-', '', $freeLineId));
        $hex = preg_replace('/[^0-9A-F]/', '', $hex) ?? '';

        return (self::PREFIXES[$family] ?? 'GEN').'-L'.substr($hex, 0, max(8, min(32, $hexLength)));
    }

    /** Descripción normalizada para detectar duplicados exactos (minúsculas, espacios colapsados). */
    public static function normalize(string $description): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $description) ?? $description));
    }
}
