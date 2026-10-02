<?php

namespace App\Domain\Quotes;

use App\Domain\QuoteFamily;

/**
 * Construye una entrada de conocimiento desde una instantánea aprobada (docs/diseno-base-conocimiento-ia.md §8.2).
 * Copia solo familia, alcance, exclusiones y por línea sku, descripción (leída del catálogo por el servidor),
 * unidad, cantidad, familia y el PRECIO DE REFERENCIA INTERNO de la partida (centavos enteros + moneda).
 * El precio de referencia nunca se envía a Anthropic ni es un precio vigente. Descarta costos, descuentos, impuestos,
 * totales, cliente, sede, cláusulas, número y autor.
 */
final class KnowledgeEntry
{
    public const OMITTED = '(descripción omitida)';

    public function __construct(private KnowledgeScrubber $scrubber) {}

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, array{sku: string, description: string}>  $catalog  Por `catalog_item_id`.
     * @param  array<string, array{sku: string, description: string}>  $freeLines  Ítem creado de cada línea libre, por `free_line_id`.
     * @return array{family: string, requirement_text: string, scope: ?string, exclusions: ?string, lines: list<array<string, mixed>>, lines_text: string, scrub_flags: list<string>, risk: string}
     */
    public function fromSnapshot(array $snapshot, array $catalog, array $freeLines = []): array
    {
        $names = [$snapshot['client_name'] ?? null, $snapshot['site_name'] ?? null];
        $flags = [];
        $risk = 'none';
        $absorb = function (array $result) use (&$flags, &$risk): string {
            array_push($flags, ...$result['flags']);
            if ($result['risk'] === 'review') {
                $risk = 'review';
            }

            return $result['text'];
        };

        $lines = [];
        foreach ((array) ($snapshot['lines'] ?? []) as $line) {
            $item = isset($line['catalog_item_id']) ? ($catalog[$line['catalog_item_id']] ?? null) : ($freeLines[$line['free_line_id'] ?? ''] ?? null);
            $description = $item === null ? self::OMITTED : $absorb($this->scrubber->scrub($item['description'], $names));
            $lines[] = [
                'sku' => $item['sku'] ?? null,
                'description' => $description === '' ? self::OMITTED : $description,
                'unit' => isset($line['unit']) ? (string) $line['unit'] : 'unidad',
                'quantity' => self::quantity($line['quantity'] ?? null),
                'family' => $line['family'] ?? null,
                'reference_price_cents' => self::cents($line['price_cents'] ?? null),
                'currency' => 'COP',
            ];
        }

        $scope = $this->clean($snapshot['scope'] ?? null, $names, $absorb);
        $exclusions = $this->clean($snapshot['exclusions'] ?? null, $names, $absorb);
        $linesText = implode(' ', array_column($lines, 'description'));
        $family = $this->family($snapshot, $lines);

        return [
            'family' => $family,
            'requirement_text' => $scope ?? ($linesText === '' ? self::OMITTED : $linesText),
            'scope' => $scope,
            'exclusions' => $exclusions,
            'lines' => $lines,
            'lines_text' => $linesText,
            'scrub_flags' => array_values(array_unique($flags)),
            'risk' => $risk,
        ];
    }

    /**
     * Entrada de un grupo de líneas de Drive (una cotización). Sin SKU ni cantidad; con precio de referencia.
     *
     * @param  list<string|null>  $names  Nombre del cliente (derivado de la fuente) a eliminar.
     * @param  list<array{section: string, price: string, description: string}>  $rows
     * @return array{family: string, requirement_text: string, scope: null, exclusions: null, lines: list<array<string, mixed>>, lines_text: string, scrub_flags: list<string>, risk: string}
     */
    public function fromDriveGroup(array $names, array $rows): array
    {
        $flags = [];
        $risk = 'none';
        $lines = [];
        $families = [];
        foreach ($rows as $row) {
            $raw = DriveLineClassifier::cleanDescription($row['description']);
            $family = DriveLineClassifier::familyOf($row['section'], $raw);
            $result = $this->scrubber->scrub($raw, $names);
            array_push($flags, ...$result['flags']);
            $risk = $result['risk'] === 'review' ? 'review' : $risk;
            [$cents, $currency] = self::parseDrivePrice($row['price']);
            $families[] = $family;
            $lines[] = [
                'sku' => null,
                'description' => $result['text'] === '' ? self::OMITTED : $result['text'],
                'unit' => DriveLineClassifier::unitOf($raw, $family),
                'quantity' => null,
                'family' => $family,
                'reference_price_cents' => $cents,
                'currency' => $currency,
            ];
        }
        $counts = array_count_values($families);
        arsort($counts);
        $linesText = implode(' ', array_column($lines, 'description'));

        return [
            'family' => (string) (array_key_first($counts) ?? QuoteFamily::Equipment->value),
            'requirement_text' => $linesText === '' ? self::OMITTED : mb_substr($linesText, 0, 1000),
            'scope' => null,
            'exclusions' => null,
            'lines' => $lines,
            'lines_text' => $linesText,
            'scrub_flags' => array_values(array_unique($flags)),
            'risk' => $risk,
        ];
    }

    /**
     * `375000` (COP) o `USD7290` a centavos enteros; sin punto flotante. Formato inválido: sin precio.
     *
     * @return array{0: ?int, 1: string}
     */
    public static function parseDrivePrice(string $value): array
    {
        $value = trim($value);
        if (! preg_match('/^(USD)?(\d{1,12})$/', $value, $m)) {
            return [null, 'COP'];
        }

        return [(int) $m[2] * 100, $m[1] === 'USD' ? 'USD' : 'COP'];
    }

    private static function cents(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 ? $value : null;
    }

    /**
     * @param  list<string|null>  $names
     */
    private function clean(mixed $value, array $names, callable $absorb): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        $text = $absorb($this->scrubber->scrub($value, $names));

        return $text === '' ? null : $text;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  list<array<string, mixed>>  $lines
     */
    private function family(array $snapshot, array $lines): string
    {
        $family = $snapshot['family'] ?? null;
        if (is_string($family) && in_array($family, QuoteFamily::values(), true)) {
            return $family;
        }
        $counts = array_count_values(array_filter(array_column($lines, 'family'), fn ($f): bool => is_string($f) && in_array($f, QuoteFamily::values(), true)));
        arsort($counts);

        return (string) (array_key_first($counts) ?? QuoteFamily::Equipment->value);
    }

    /** Cantidad como cadena decimal de 3 posiciones, sin pasar por punto flotante. */
    public static function quantity(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }
        $value = (string) $value;
        if (! preg_match('/^(\d+)(?:\.(\d{1,3}))?$/', $value, $m)) {
            return null;
        }

        return $m[1].'.'.str_pad($m[2] ?? '', 3, '0');
    }
}
