<?php

namespace App\Domain\Quotes;

/**
 * Compara las líneas propuestas por la IA (sku, cantidad) con las aprobadas (docs/diseno-base-conocimiento-ia.md §9.2).
 * Puro: sin precios ni acceso a datos. Las cantidades se comparan en milésimas enteras, sin punto flotante.
 */
final class AssistDiff
{
    /**
     * @param  list<array<string, mixed>>  $proposed  `sku`, `quantity`.
     * @param  list<array<string, mixed>>  $approved  `sku`, `quantity`.
     * @return array{kept: int, qty_changed: int, removed: int, added: int, human_edit_ratio: string}
     */
    public static function compare(array $proposed, array $approved): array
    {
        $before = self::bySku($proposed);
        $after = self::bySku($approved);
        $kept = $changed = $removed = 0;
        foreach ($before as $sku => $qty) {
            if (! array_key_exists($sku, $after)) {
                $removed++;
            } elseif ($after[$sku] === $qty) {
                $kept++;
            } else {
                $changed++;
            }
        }
        $added = count(array_diff_key($after, $before)) + self::unmatched($approved);
        $total = $kept + $changed + $removed + $added;
        $ratio = $total === 0 ? 0 : ($changed + $removed + $added) / $total;

        return [
            'kept' => $kept, 'qty_changed' => $changed, 'removed' => $removed, 'added' => $added,
            'human_edit_ratio' => number_format($ratio, 3, '.', ''),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, int> Milésimas por SKU (se suman los SKU repetidos).
     */
    private static function bySku(array $lines): array
    {
        $map = [];
        foreach ($lines as $line) {
            $sku = $line['sku'] ?? null;
            if (! is_string($sku) || $sku === '') {
                continue;
            }
            $map[$sku] = ($map[$sku] ?? 0) + self::milli($line['quantity'] ?? null);
        }

        return $map;
    }

    /** Líneas aprobadas sin SKU (fuera del catálogo): cuentan como añadidas. */
    private static function unmatched(array $lines): int
    {
        return count(array_filter($lines, fn ($l): bool => ! is_string($l['sku'] ?? null) || $l['sku'] === ''));
    }

    private static function milli(mixed $value): int
    {
        if (! is_string($value) && ! is_int($value)) {
            return 0;
        }
        if (! preg_match('/^(\d+)(?:\.(\d{1,3}))?$/', (string) $value, $m)) {
            return 0;
        }

        return (int) $m[1] * 1000 + (int) str_pad($m[2] ?? '', 3, '0');
    }
}
