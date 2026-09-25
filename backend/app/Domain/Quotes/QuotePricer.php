<?php

namespace App\Domain\Quotes;

use App\Repositories\Contracts\PricingRepository;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class QuotePricer
{
    public function __construct(private QuoteCalculator $calculator, private PricingRepository $prices) {}

    public function calculate(array $inputLines, int $vatWithholdingBps = 0): array
    {
        $today = now('America/Bogota')->toDateString();
        $lines = [];
        foreach ($inputLines as $index => $line) {
            [$price, $item] = $this->prices->lockedPrice($line['price_version_id']);
            if (! $price || ! $item || ! $item->active || $price->status !== 'approved' || $price->valid_from > $today || $price->valid_until < $today) {
                throw ValidationException::withMessages(["lines.{$index}.price_version_id" => 'El precio debe estar aprobado y vigente, y el ítem activo.']);
            }
            $lines[] = [
                'catalog_item_id' => $item->id,
                'price_version_id' => $price->id,
                'description' => $item->description,
                'unit' => $item->unit,
                'family' => $item->family,
                'is_demo' => (bool) $item->is_demo,
                'quantity' => $line['quantity'],
                'price_cents' => (int) $price->price_cents,
                'cost_cents' => (int) $price->cost_cents,
                'tax_bps' => (int) $price->tax_bps,
                'discount_bps' => (int) $line['discount_bps'],
            ];
        }
        try {
            $calculation = $this->calculator->calculate($lines, $vatWithholdingBps);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['lines' => $exception->getMessage()]);
        }

        return $calculation;
    }
}
