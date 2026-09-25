<?php

namespace App\Domain;

use Illuminate\Validation\ValidationException;

final class DecimalMoney
{
    public static function cents(string $value, string $field, int $max = 1000000000): int
    {
        if (! preg_match('/^\d{1,16}(\.\d{1,2})?$/D', $value)) {
            throw ValidationException::withMessages([$field => 'Usa un valor en COP sin separadores de miles y con máximo dos decimales.']);
        }
        [$whole, $fraction] = array_pad(explode('.', $value), 2, '');
        $cents = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
        if ($cents > $max) {
            throw ValidationException::withMessages([$field => 'El valor supera el máximo permitido.']);
        }

        return $cents;
    }
}
