<?php

namespace App\Domain\Quotes;

use App\Domain\QuoteFamily;

/**
 * Validador/normalizador puro de la salida del asistente. Todo lo que no sea
 * cantidad, SKU o texto libre acotado sale del catálogo del servidor.
 */
final class AssistantProposal
{
    public const MAX_LINES = 30;

    public const MAX_TEXT = 3000;

    public const MAX_MISSING = 10;

    public const MAX_MISSING_CHARS = 200;

    /**
     * @param  array<string, mixed>  $output
     * @param  array<string, array{price_version_id: string, description: string, unit: string, family: string}>  $catalog  clave: SKU
     * @return array{family: ?string, scope: ?string, exclusions: ?string, lines: list<array<string, mixed>>, missing_information: list<string>, warnings: list<array<string, string>>, lines_discarded: int}
     */
    public function validate(array $output, array $catalog): array
    {
        $warnings = [];
        $family = $output['family'] ?? null;
        if ($family !== null && (! is_string($family) || QuoteFamily::tryFrom($family) === null)) {
            $family = null;
            $warnings[] = ['code' => 'invalid_family'];
        }

        $rawLines = is_array($output['lines'] ?? null) ? array_values($output['lines']) : [];
        $discarded = max(0, count($rawLines) - self::MAX_LINES);
        $lines = [];
        $seen = [];
        foreach (array_slice($rawLines, 0, self::MAX_LINES) as $raw) {
            $sku = is_array($raw) && is_string($raw['sku'] ?? null) ? $raw['sku'] : null;
            $shown = $sku !== null && preg_match('/^[A-Z0-9_-]{1,60}$/D', $sku) ? ['sku' => $sku] : [];
            if ($sku === null || ! isset($catalog[$sku])) {
                $warnings[] = ['code' => 'unknown_sku'] + $shown;
                $discarded++;

                continue;
            }
            if (isset($seen[$sku])) {
                $warnings[] = ['code' => 'duplicate_sku', 'sku' => $sku];
                $discarded++;

                continue;
            }
            $quantity = $this->quantity($raw['quantity'] ?? null);
            if ($quantity === null) {
                $warnings[] = ['code' => 'invalid_quantity', 'sku' => $sku];
                $discarded++;

                continue;
            }
            $seen[$sku] = true;
            $item = $catalog[$sku];
            if ($family !== null && $item['family'] !== $family) {
                $warnings[] = ['code' => 'family_mismatch', 'sku' => $sku];
            }
            $lines[] = [
                'sku' => $sku, 'price_version_id' => $item['price_version_id'], 'description' => $item['description'],
                'unit' => $item['unit'], 'family' => $item['family'], 'quantity' => $quantity, 'discount_bps' => 0,
            ];
        }

        $missing = [];
        $rawMissing = is_array($output['missing_information'] ?? null) ? array_values($output['missing_information']) : [];
        foreach (array_slice($rawMissing, 0, self::MAX_MISSING) as $entry) {
            $text = $this->text($entry, self::MAX_MISSING_CHARS, $warnings);
            if ($text !== null) {
                $missing[] = $text;
            }
        }

        return [
            'family' => $family,
            'scope' => $this->text($output['scope'] ?? null, self::MAX_TEXT, $warnings),
            'exclusions' => $this->text($output['exclusions'] ?? null, self::MAX_TEXT, $warnings),
            'lines' => $lines, 'missing_information' => $missing, 'warnings' => $warnings, 'lines_discarded' => $discarded,
        ];
    }

    /** Solo cadenas decimales positivas (hasta 5 enteros y 3 decimales); devuelve con 3 decimales. */
    private function quantity(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^(\d{1,5})(?:\.(\d{1,3}))?$/D', $value, $m)) {
            return null;
        }
        $decimals = str_pad($m[2] ?? '', 3, '0');
        if ((int) $m[1] === 0 && (int) $decimals === 0) {
            return null;
        }

        return ((int) $m[1]).'.'.$decimals;
    }

    /** @param list<array<string, string>> $warnings */
    private function text(mixed $value, int $max, array &$warnings): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $clean = trim(preg_replace(['/\p{Cf}+/u', '/[^\P{Cc}\n]+/u', '/[ \t]+/', '/\n{3,}/'], ['', ' ', ' ', "\n\n"], $value) ?? '');
        $clean = trim(mb_substr($clean, 0, $max));
        if ($clean === '') {
            return null;
        }
        if (ClauseText::looksLikeBankAccount($clean)) {
            $warnings[] = ['code' => 'sensitive_text_removed'];

            return null;
        }

        return $clean;
    }
}
