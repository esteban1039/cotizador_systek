<?php

namespace App\Domain\Quotes;

/**
 * Interpretación de lectura de instantáneas anteriores a esta iteración
 * (sin familia, cláusulas ni ReteIVA). Nunca se persiste: se usa en
 * `show`, `index`, el PDF y `ApprovalValidation`.
 */
final class SnapshotCompatibility
{
    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public static function normalize(array $snapshot, object $record): array
    {
        $snapshot['family'] ??= null;
        $snapshot['validity_terms'] ??= null;
        $snapshot['observations'] ??= null;
        $snapshot['clauses'] ??= [];
        $snapshot['vat_withholding'] ??= ['applied' => false, 'rate_bps' => 0, 'basis' => 'tax_total'];
        $snapshot['totals']['vat_withholding'] ??= '0.00';
        $snapshot['totals']['payable'] ??= $snapshot['totals']['total'];
        $snapshot['quote_number'] = $record->quote_number ?? ($snapshot['quote_number'] ?? null);

        return $snapshot;
    }
}
