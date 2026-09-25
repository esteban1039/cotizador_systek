<?php

namespace App\Domain\Quotes;

use InvalidArgumentException;

final class QuoteCalculator
{
    /**
     * Input amounts are integer cents, quantities are decimal strings.
     *
     * $vatWithholdingBps: tarifa de ReteIVA (0..10000) aplicada una sola vez
     * sobre el IVA total (App\Domain\Quotes\VatWithholdingPolicy).
     */
    public function calculate(array $lines, int $vatWithholdingBps = 0): array
    {
        if (count($lines) < 1 || count($lines) > 100) {
            throw new InvalidArgumentException('La cotización debe tener entre 1 y 100 partidas.');
        }
        if ($vatWithholdingBps < 0 || $vatWithholdingBps > 10000) {
            throw new InvalidArgumentException('La tarifa de ReteIVA debe estar entre 0 y 10000 puntos básicos.');
        }
        $totals = ['gross' => 0, 'discount' => 0, 'subtotal' => 0, 'tax' => 0, 'total' => 0, 'cost' => 0];
        $result = [];
        foreach ($lines as $line) {
            $quantity = $line['quantity'];
            if (! is_string($quantity) || ! preg_match('/^\d{1,5}(\.\d{1,3})?$/D', $quantity)) {
                throw new InvalidArgumentException('Cantidad inválida: usa una cadena decimal positiva con hasta tres decimales.');
            }
            [$whole, $fraction] = array_pad(explode('.', $quantity), 2, '');
            $units = (int) $whole * 1000 + (int) str_pad($fraction, 3, '0');
            if ($units === 0) {
                throw new InvalidArgumentException('La cantidad debe ser mayor que cero.');
            }
            foreach (['price_cents', 'cost_cents', 'tax_bps', 'discount_bps'] as $key) {
                $max = str_ends_with($key, '_bps') ? 10000 : 1000000000;
                if (! is_int($line[$key]) || $line[$key] < 0 || $line[$key] > $max) {
                    throw new InvalidArgumentException("Valor fuera de rango: {$key}.");
                }
            }
            $gross = $this->round($line['price_cents'] * $units, 1000);
            $discount = $this->round($gross * $line['discount_bps'], 10000);
            $subtotal = $gross - $discount;
            $tax = $this->round($subtotal * $line['tax_bps'], 10000);
            $cost = $this->round($line['cost_cents'] * $units, 1000);
            $amounts = compact('gross', 'discount', 'subtotal', 'tax', 'cost');
            $amounts['total'] = $subtotal + $tax;
            foreach ($amounts as $key => $amount) {
                $totals[$key] += $amount;
            }
            $result[] = array_merge($line, ['amounts' => array_map($this->format(...), $amounts)]);
        }

        // ReteIVA: un único redondeo half-up sobre el IVA total. Descomposición
        // q/r para evitar desbordar PHP_INT_MAX (tax puede llegar a ~1e16
        // centavos con 100 partidas en el máximo, y tax * bps desbordaría).
        $tax = $totals['tax'];
        $quotient = intdiv($tax, 10000);
        $remainder = $tax % 10000;
        $vatWithholding = $quotient * $vatWithholdingBps + intdiv($remainder * $vatWithholdingBps + 5000, 10000);
        $totals['vat_withholding'] = $vatWithholding;
        $totals['payable'] = $totals['total'] - $vatWithholding;

        return [
            'currency' => 'COP',
            'lines' => $result,
            'totals' => array_map($this->format(...), $totals),
            'profit' => $this->format($totals['subtotal'] - $totals['cost']),
        ];
    }

    private function round(int $numerator, int $denominator): int
    {
        return intdiv($numerator + intdiv($denominator, 2), $denominator);
    }

    private function format(int $cents): string
    {
        return ($cents < 0 ? '-' : '').intdiv(abs($cents), 100).'.'.str_pad((string) (abs($cents) % 100), 2, '0', STR_PAD_LEFT);
    }
}
