<?php

namespace App\Domain\Quotes;

use App\Domain\DecimalMoney;
use App\Repositories\Contracts\CatalogRepository;
use App\Repositories\Contracts\ClientRepository;
use App\Repositories\Contracts\PricingRepository;
use App\Repositories\Contracts\QuoteRepository;
use Illuminate\Validation\ValidationException;

final class ApprovalValidation
{
    public const MODE_APPROVAL = 'approval';

    public const MODE_EMISSION = 'emission';

    public function __construct(
        private QuotePricer $pricer,
        private QuoteRepository $quotes,
        private CatalogRepository $catalog,
        private PricingRepository $prices,
        private ClientRepository $clients,
        private ClauseCoherence $coherence,
    ) {}

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  self::MODE_*  $mode  `approval`: las líneas libres usan los valores de la instantánea.
     *                              `emission`: se resuelven por su vínculo y deben coincidir con el ítem y precio vigentes.
     */
    public function check(array $snapshot, object $record, bool $requireRules = true, string $mode = self::MODE_APPROVAL): array
    {
        if ($snapshot['valid_until'] < now()->toDateString()) {
            throw ValidationException::withMessages(['quote' => 'La vigencia del borrador terminó. Crea una nueva cotización.']);
        }

        $snapshot = SnapshotCompatibility::normalize($snapshot, $record);

        $clientId = $record->client_id ?? $snapshot['client_id'];
        $withholds = (bool) $this->clients->lockedTaxProfile($clientId);
        $expectedRate = VatWithholdingPolicy::rateFor($withholds);
        $snapshotVatWithholding = $snapshot['vat_withholding'];
        if ($withholds !== (bool) ($snapshotVatWithholding['applied'] ?? false) || $expectedRate !== (int) ($snapshotVatWithholding['rate_bps'] ?? 0)) {
            throw ValidationException::withMessages(['quote' => 'La condición de ReteIVA del cliente o su tarifa cambió desde que se guardó. Crea una nueva revisión.']);
        }

        $inputLines = $mode === self::MODE_EMISSION ? $this->emissionLines($snapshot['lines'], (string) $record->id) : $snapshot['lines'];
        $calculated = $this->pricer->calculate($inputLines, $expectedRate, $snapshot['family']);
        if ($mode === self::MODE_EMISSION) {
            $this->assertFreeLinesUnchanged($snapshot['lines'], $calculated['lines']);
        }
        foreach (['gross', 'discount', 'subtotal', 'tax', 'total', 'cost', 'vat_withholding', 'payable'] as $key) {
            if (($calculated['totals'][$key] ?? null) !== ($snapshot['totals'][$key] ?? null)) {
                throw ValidationException::withMessages(['quote' => 'Los totales no coinciden con el cálculo actual. Crea un nuevo borrador.']);
            }
        }

        $flags = $mode === self::MODE_APPROVAL ? $this->freeLineFlags($snapshot['lines'], (string) $snapshot['family']) : [];
        $rules = [];
        foreach ($calculated['lines'] as $index => $line) {
            $rule = $this->prices->lockedRule($line['family']);
            if (! $rule) {
                if ($requireRules) {
                    throw ValidationException::withMessages(['rules' => 'Faltan reglas comerciales para la familia '.$line['family'].'. El administrador debe configurarlas.']);
                }

                continue;
            }
            $rules[$line['family']] = (array) $rule;
            $subtotal = DecimalMoney::cents($line['amounts']['subtotal'], 'subtotal', PHP_INT_MAX);
            $cost = DecimalMoney::cents($line['amounts']['cost'], 'cost', PHP_INT_MAX);
            if ($subtotal === 0 || ($subtotal - $cost) * 10000 < $subtotal * $rule->minimum_margin_bps) {
                $flags[] = 'Partida '.($index + 1).': margen inferior al mínimo configurado.';
            }
            if ($line['discount_bps'] > $rule->max_discount_bps) {
                $flags[] = 'Partida '.($index + 1).': descuento superior al límite configurado.';
            }
            if (DecimalMoney::cents($calculated['totals']['total'], 'total', PHP_INT_MAX) > $rule->review_above_cents) {
                $flags[] = 'Total superior al umbral de revisión de '.$line['family'].'.';
            }
        }

        $coherence = $this->coherence->check($snapshot, $calculated['lines'], $requireRules);

        return [
            'flags' => array_values(array_unique(array_merge($flags, $coherence['flags']))),
            'rules' => $rules,
            'clauses' => $coherence['clauses'],
            'checked_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Cada línea libre pasa a ser una línea de catálogo (ítem creado al aprobar), de modo que
     * `QuotePricer` aplique el bloqueo ítem -> precio y exija ítem activo y precio vigente.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array<string, mixed>>
     */
    private function emissionLines(array $lines, string $quoteId): array
    {
        $links = null;
        foreach ($lines as $index => $line) {
            if (($line['line_type'] ?? 'catalog') !== 'free') {
                continue;
            }
            $links ??= $this->quotes->freeLineLinks($quoteId);
            $link = $links[$line['free_line_id'] ?? ''] ?? null;
            if ($link === null) {
                throw ValidationException::withMessages(["lines.{$index}" => 'La línea libre aún no tiene ítem de catálogo; la cotización debe aprobarse de nuevo.']);
            }
            $lines[$index] = [
                'price_version_id' => $link['price_version_id'],
                'quantity' => $line['quantity'],
                'discount_bps' => $line['discount_bps'],
            ];
        }

        return $lines;
    }

    /**
     * @param  array<int, array<string, mixed>>  $snapshotLines
     * @param  array<int, array<string, mixed>>  $calculatedLines
     */
    private function assertFreeLinesUnchanged(array $snapshotLines, array $calculatedLines): void
    {
        foreach ($snapshotLines as $index => $line) {
            if (($line['line_type'] ?? 'catalog') !== 'free') {
                continue;
            }
            foreach (['price_cents', 'cost_cents', 'tax_bps'] as $key) {
                if (($calculatedLines[$index][$key] ?? null) !== ($line[$key] ?? null)) {
                    throw ValidationException::withMessages(["lines.{$index}.price_version_id" => 'El precio del ítem creado cambió desde la aprobación. Crea una revisión.']);
                }
            }
        }
    }

    /**
     * Avisos (no bloqueos) de las líneas libres en modo aprobación.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return list<string>
     */
    private function freeLineFlags(array $lines, string $family): array
    {
        $flags = [];
        foreach ($lines as $index => $line) {
            if (($line['line_type'] ?? 'catalog') !== 'free') {
                continue;
            }
            $number = $index + 1;
            $flags[] = "Partida {$number}: línea libre: al aprobar se crea ítem activo con precio vigente.";
            $match = $this->catalog->activeExactMatch((string) $line['description'], (string) ($line['family'] ?? $family));
            if ($match !== null) {
                $flags[] = "Partida {$number}: posible duplicado de SKU {$match['sku']}.";
            }
        }

        return $flags;
    }
}
