<?php

namespace App\Domain\Quotes;

use App\Domain\DecimalMoney;
use App\Repositories\Contracts\ClientRepository;
use App\Repositories\Contracts\PricingRepository;
use Illuminate\Validation\ValidationException;

final class ApprovalValidation
{
    public function __construct(
        private QuotePricer $pricer,
        private PricingRepository $prices,
        private ClientRepository $clients,
        private ClauseCoherence $coherence,
    ) {}

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function check(array $snapshot, object $record, bool $requireRules = true): array
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

        $calculated = $this->pricer->calculate($snapshot['lines'], $expectedRate);
        foreach (['gross', 'discount', 'subtotal', 'tax', 'total', 'cost', 'vat_withholding', 'payable'] as $key) {
            if (($calculated['totals'][$key] ?? null) !== ($snapshot['totals'][$key] ?? null)) {
                throw ValidationException::withMessages(['quote' => 'Los totales no coinciden con el cálculo actual. Crea un nuevo borrador.']);
            }
        }

        $flags = [];
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
}
