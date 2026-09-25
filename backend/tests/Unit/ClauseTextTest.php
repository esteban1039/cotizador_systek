<?php

namespace Tests\Unit;

use App\Domain\Quotes\ClauseText;
use PHPUnit\Framework\TestCase;

final class ClauseTextTest extends TestCase
{
    public function test_normalize_trims_collapses_whitespace_and_lowercases(): void
    {
        $this->assertSame('anticipo 50 % / 50 %', ClauseText::normalize("  Anticipo   50 %  /  50 %\n\t"));
    }

    public function test_hash_is_stable_across_equivalent_whitespace_and_case(): void
    {
        $this->assertSame(ClauseText::hash('Contado.'), ClauseText::hash("  CONTADO.  \n"));
        $this->assertNotSame(ClauseText::hash('Contado.'), ClauseText::hash('Contado, 30 días.'));
    }

    public function test_render_replaces_only_the_validity_days_marker_literally(): void
    {
        $this->assertSame(
            'Vigencia de 15 días calendario.',
            ClauseText::render('Vigencia de {vigencia_dias} días calendario.', 15)
        );
        $this->assertSame('Sin marcador.', ClauseText::render('Sin marcador.', 30));
    }

    public function test_validate_rejects_tokens_other_than_vigencia_dias(): void
    {
        $errors = ClauseText::validate('Texto con {otro_token} no permitido.');
        $this->assertNotEmpty($errors);
    }

    public function test_validate_accepts_the_vigencia_dias_token(): void
    {
        $this->assertSame([], ClauseText::validate('Vigencia de {vigencia_dias} días.'));
    }

    public function test_validate_rejects_bank_account_like_digit_sequences(): void
    {
        $this->assertNotEmpty(ClauseText::validate('Consignar a la cuenta 12345678.'));
        $this->assertNotEmpty(ClauseText::validate('Cuenta 123 456 78.'));
        $this->assertNotEmpty(ClauseText::validate('Cuenta 123-456-78.'));
    }

    public function test_validate_accepts_short_digit_sequences(): void
    {
        $this->assertSame([], ClauseText::validate('Garantía de 12 meses sobre defectos de fábrica.'));
    }
}
