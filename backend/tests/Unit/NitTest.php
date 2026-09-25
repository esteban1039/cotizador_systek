<?php

namespace Tests\Unit;

use App\Domain\Nit;
use PHPUnit\Framework\TestCase;

final class NitTest extends TestCase
{
    public function test_systek_nit_check_digit_is_one(): void
    {
        // Suma 661; 661 mod 11 = 1 (r ∈ {0,1} ⇒ DV = r).
        $this->assertSame(1, Nit::checkDigit('901704107'));
        $this->assertSame('901704107-1', Nit::normalize('901704107-1'));
    }

    public function test_check_digit_when_remainder_is_zero(): void
    {
        $this->assertSame(0, Nit::checkDigit('900000009'));
        $this->assertSame('900000009-0', Nit::normalize('900000009-0'));
    }

    public function test_check_digit_when_remainder_is_one(): void
    {
        $this->assertSame(1, Nit::checkDigit('900000002'));
        $this->assertSame('900000002-1', Nit::normalize('900000002-1'));
    }

    public function test_normalize_accepts_dots_spaces_and_hyphen(): void
    {
        $this->assertSame('901704107-1', Nit::normalize('901.704.107-1'));
        $this->assertSame('901704107-1', Nit::normalize('901 704 107 1'));
        $this->assertSame('901704107-1', Nit::normalize('901704107 1'));
    }

    public function test_normalize_rejects_wrong_check_digit(): void
    {
        $this->assertNull(Nit::normalize('901704107-2'));
    }

    public function test_normalize_rejects_lengths_out_of_range(): void
    {
        $this->assertNull(Nit::normalize('123-4'));
        $this->assertNull(Nit::normalize(str_repeat('9', 16).'-1'));
    }
}
