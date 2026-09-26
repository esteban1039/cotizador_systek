<?php

namespace Tests\Unit;

use App\Domain\Quotes\AssistantProposal;
use PHPUnit\Framework\TestCase;

final class AssistantProposalTest extends TestCase
{
    /** @return array<string, array<string, string>> */
    private function catalog(): array
    {
        return [
            'CAM-1' => ['price_version_id' => 'pv-1', 'description' => 'Cámara', 'unit' => 'unidad', 'family' => 'cctv'],
            'UPS-1' => ['price_version_id' => 'pv-2', 'description' => 'UPS', 'unit' => 'unidad', 'family' => 'ups'],
        ];
    }

    /** @return list<string> */
    private function codes(array $result): array
    {
        return array_column($result['warnings'], 'code');
    }

    public function test_valid_output_is_normalized_with_server_side_fields(): void
    {
        $result = (new AssistantProposal)->validate([
            'family' => 'cctv', 'scope' => "  Ocho\x00 cámaras.  ", 'exclusions' => null, 'missing_information' => ['Altura de montaje'],
            'lines' => [['sku' => 'CAM-1', 'quantity' => '8', 'price_version_id' => 'evil', 'description' => 'x', 'discount_bps' => 900]],
        ], $this->catalog());

        $this->assertSame([[
            'sku' => 'CAM-1', 'price_version_id' => 'pv-1', 'description' => 'Cámara', 'unit' => 'unidad', 'family' => 'cctv', 'quantity' => '8.000', 'discount_bps' => 0,
        ]], $result['lines']);
        $this->assertSame('Ocho cámaras.', $result['scope']);
        $this->assertNull($result['exclusions']);
        $this->assertSame(['Altura de montaje'], $result['missing_information']);
        $this->assertSame([], $result['warnings']);
    }

    public function test_unknown_sku_invalid_quantities_and_duplicates_are_discarded(): void
    {
        $lines = [
            ['sku' => 'NOPE', 'quantity' => '1'], ['sku' => 'CAM-1', 'quantity' => 3], ['sku' => 'CAM-1', 'quantity' => '1e3'],
            ['sku' => 'CAM-1', 'quantity' => '0'], ['sku' => 'CAM-1', 'quantity' => '-1'], ['sku' => 'CAM-1', 'quantity' => '1.2345'],
            ['sku' => 'CAM-1', 'quantity' => '2.5'], ['sku' => 'CAM-1', 'quantity' => '4'], 'basura', ['sku' => ['x'], 'quantity' => '1'],
        ];
        $result = (new AssistantProposal)->validate(['family' => null, 'lines' => $lines], $this->catalog());

        $this->assertCount(1, $result['lines']);
        $this->assertSame('2.500', $result['lines'][0]['quantity']);
        $this->assertSame(9, $result['lines_discarded']);
        $codes = $this->codes($result);
        $this->assertSame(3, count(array_keys($codes, 'unknown_sku')));
        $this->assertContains('invalid_quantity', $codes);
        $this->assertContains('duplicate_sku', $codes);
    }

    public function test_invalid_family_becomes_null_and_mismatch_warns(): void
    {
        $bad = (new AssistantProposal)->validate(['family' => 'nuclear', 'lines' => []], $this->catalog());
        $this->assertNull($bad['family']);
        $this->assertSame(['invalid_family'], $this->codes($bad));

        $mismatch = (new AssistantProposal)->validate(['family' => 'cctv', 'lines' => [['sku' => 'UPS-1', 'quantity' => '1']]], $this->catalog());
        $this->assertCount(1, $mismatch['lines']);
        $this->assertSame([['code' => 'family_mismatch', 'sku' => 'UPS-1']], $mismatch['warnings']);
    }

    public function test_invisible_and_bidi_control_characters_are_stripped_from_texts(): void
    {
        $result = (new AssistantProposal)->validate([
            'family' => null, 'lines' => [], 'scope' => "Alcance\u{202E}invertido\u{200B}oculto", 'exclusions' => null, 'missing_information' => [],
        ], $this->catalog());

        $this->assertSame('Alcanceinvertidooculto', $result['scope']);
    }

    public function test_texts_with_bank_account_sequences_are_removed_and_long_texts_cut(): void
    {
        $result = (new AssistantProposal)->validate([
            'family' => null, 'lines' => [], 'scope' => 'Consignar a la cuenta 5550001234567', 'exclusions' => str_repeat('a', 3500),
            'missing_information' => ['Cuenta 1234 5678 9012', str_repeat('b', 300), 'Otro', 5],
        ], $this->catalog());

        $this->assertNull($result['scope']);
        $this->assertSame(3000, mb_strlen((string) $result['exclusions']));
        $this->assertSame([str_repeat('b', 200), 'Otro'], $result['missing_information']);
        $this->assertSame(['sensitive_text_removed', 'sensitive_text_removed'], $this->codes($result));
    }

    public function test_lines_are_capped_at_thirty(): void
    {
        $lines = array_fill(0, 35, ['sku' => 'NOPE', 'quantity' => '1']);
        $result = (new AssistantProposal)->validate(['lines' => $lines], $this->catalog());

        $this->assertSame(35, $result['lines_discarded']);
        $this->assertCount(30, $result['warnings']);
    }
}
