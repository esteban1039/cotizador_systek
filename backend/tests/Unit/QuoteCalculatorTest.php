<?php

namespace Tests\Unit;

use App\Domain\Quotes\QuoteCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QuoteCalculatorTest extends TestCase
{
    private function line(array $overrides = []): array
    {
        return array_merge(['quantity' => '8', 'price_cents' => 20000000, 'cost_cents' => 15000000, 'tax_bps' => 1900, 'discount_bps' => 0], $overrides);
    }

    public function test_eight_cameras_with_tax(): void
    {
        $result = (new QuoteCalculator)->calculate([$this->line()]);
        $this->assertSame('1600000.00', $result['totals']['subtotal']);
        $this->assertSame('304000.00', $result['totals']['tax']);
        $this->assertSame('1904000.00', $result['totals']['total']);
        $this->assertSame('400000.00', $result['profit']);
    }

    public function test_fractional_quantity_rounds_half_up_then_discounts_then_tax(): void
    {
        $result = (new QuoteCalculator)->calculate([$this->line(['quantity' => '1.005', 'price_cents' => 100, 'cost_cents' => 0, 'discount_bps' => 1000])]);
        $this->assertSame('1.01', $result['totals']['gross']);
        $this->assertSame('0.10', $result['totals']['discount']);
        $this->assertSame('0.91', $result['totals']['subtotal']);
        $this->assertSame('0.17', $result['totals']['tax']);
        $this->assertSame('1.08', $result['totals']['total']);
    }

    public function test_full_discount_preserves_negative_profit_and_zero_tax(): void
    {
        $result = (new QuoteCalculator)->calculate([$this->line(['discount_bps' => 10000])]);
        $this->assertSame('0.00', $result['totals']['total']);
        $this->assertSame('-1200000.00', $result['profit']);
    }

    public function test_mixed_tax_rates_are_calculated_per_line(): void
    {
        $result = (new QuoteCalculator)->calculate([$this->line(), $this->line(['tax_bps' => 0])]);
        $this->assertSame('3504000.00', $result['totals']['total']);
    }

    #[DataProvider('invalidLines')]
    public function test_invalid_input_is_rejected(array $overrides): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new QuoteCalculator)->calculate([$this->line($overrides)]);
    }

    public static function invalidLines(): array
    {
        return array_map(fn ($value) => [$value], [
            ['quantity' => '0'], ['quantity' => '000.000'], ['quantity' => '-1'], ['quantity' => '1.0001'],
            ['quantity' => '1e3'], ['quantity' => 1.5], ['quantity' => '100000'], ['price_cents' => -1],
            ['price_cents' => 1000000001], ['price_cents' => 1.1], ['discount_bps' => 10001], ['tax_bps' => -1],
        ]);
    }

    public function test_maximum_supported_values_do_not_overflow(): void
    {
        $result = (new QuoteCalculator)->calculate(array_fill(0, 100, $this->line([
            'quantity' => '99999.999', 'price_cents' => 1000000000, 'tax_bps' => 10000,
        ])));
        $this->assertSame('199999998000000.00', $result['totals']['total']);
    }

    public function test_vat_withholding_on_eight_cameras_example(): void
    {
        $result = (new QuoteCalculator)->calculate([$this->line()], 1500);
        $this->assertSame('304000.00', $result['totals']['tax']);
        $this->assertSame('45600.00', $result['totals']['vat_withholding']);
        $this->assertSame('1858400.00', $result['totals']['payable']);
    }

    #[DataProvider('vatWithholdingRoundingCases')]
    public function test_vat_withholding_rounds_half_up_on_the_total_tax(int $taxCents, string $expectedWithholding): void
    {
        // Two lines whose tax sums to $taxCents, to prove the rounding
        // happens once on the total tax, not per line.
        $half = intdiv($taxCents, 2);
        $rest = $taxCents - $half;
        $result = (new QuoteCalculator)->calculate([
            $this->line(['price_cents' => $half, 'quantity' => '1', 'tax_bps' => 10000, 'discount_bps' => 0]),
            $this->line(['price_cents' => $rest, 'quantity' => '1', 'tax_bps' => 10000, 'discount_bps' => 0]),
        ], 1500);
        $this->assertSame($taxCents, (int) round(((float) $result['totals']['tax']) * 100));
        $this->assertSame($expectedWithholding, $result['totals']['vat_withholding']);
    }

    public static function vatWithholdingRoundingCases(): array
    {
        // tax in cents => expected ReteIVA (15%) formatted, half-up to the cent.
        return [
            'tax 0.17 rounds up to 0.03' => [17, '0.03'],
            'tax 0.10 rounds up to 0.02 (half-up exact)' => [10, '0.02'],
            'tax 0.03 rounds down to 0.00' => [3, '0.00'],
            'tax 0.04 rounds up to 0.01' => [4, '0.01'],
        ];
    }

    public function test_two_lines_with_ten_cent_tax_each_round_on_the_combined_total_not_per_line(): void
    {
        $line = $this->line(['price_cents' => 10, 'quantity' => '1', 'tax_bps' => 10000, 'discount_bps' => 0]);
        $result = (new QuoteCalculator)->calculate([$line, $line], 1500);
        $this->assertSame('0.20', $result['totals']['tax']);
        // Rounding 0.10 -> 0.02 per line would wrongly total 0.04; rounding the
        // combined 0.20 once gives 0.03.
        $this->assertSame('0.03', $result['totals']['vat_withholding']);
    }

    public function test_full_discount_gives_zero_payable_and_zero_bps_leaves_payable_equal_to_total(): void
    {
        $fullDiscount = (new QuoteCalculator)->calculate([$this->line(['discount_bps' => 10000])], 1500);
        $this->assertSame('0.00', $fullDiscount['totals']['vat_withholding']);
        $this->assertSame('0.00', $fullDiscount['totals']['payable']);

        $zeroBps = (new QuoteCalculator)->calculate([$this->line()], 0);
        $this->assertSame('0.00', $zeroBps['totals']['vat_withholding']);
        $this->assertSame($zeroBps['totals']['total'], $zeroBps['totals']['payable']);
    }

    public function test_maximum_values_with_vat_withholding_do_not_overflow(): void
    {
        $result = (new QuoteCalculator)->calculate(array_fill(0, 100, $this->line([
            'quantity' => '99999.999', 'price_cents' => 1000000000, 'tax_bps' => 10000,
        ])), 1500);
        $toCents = static function (string $value): int {
            [$whole, $fraction] = array_pad(explode('.', $value), 2, '00');

            return ((int) $whole) * 100 + (int) $fraction;
        };
        $this->assertIsString($result['totals']['vat_withholding']);
        $this->assertIsString($result['totals']['payable']);
        // No overflow to float: exact integer-cents identity, without depending on a hand-computed magic number.
        $this->assertSame($toCents($result['totals']['total']), $toCents($result['totals']['vat_withholding']) + $toCents($result['totals']['payable']));
        $this->assertSame('14999999850000.00', $result['totals']['vat_withholding']);
    }

    #[DataProvider('invalidVatWithholdingBps')]
    public function test_vat_withholding_bps_out_of_range_is_rejected(int $bps): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new QuoteCalculator)->calculate([$this->line()], $bps);
    }

    public static function invalidVatWithholdingBps(): array
    {
        return [[-1], [10001]];
    }
}
