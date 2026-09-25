<?php

namespace App\Domain;

/**
 * Validación del NIT (Colombia) con el dígito de verificación (DV) DIAN.
 * Por ahora solo se usa para la empresa emisora; el NIT de clientes no
 * cambia en esta iteración.
 */
final class Nit
{
    /** Pesos DIAN desde el dígito menos significativo. */
    private const WEIGHTS = [3, 7, 13, 17, 19, 23, 29, 37, 41, 43, 47, 53, 59, 67, 71];

    private const MIN_BASE_LENGTH = 5;

    private const MAX_BASE_LENGTH = 15;

    public static function checkDigit(string $base): int
    {
        $digits = array_reverse(str_split($base));
        $sum = 0;
        foreach ($digits as $index => $digit) {
            $sum += ((int) $digit) * (self::WEIGHTS[$index] ?? 0);
        }
        $remainder = $sum % 11;

        return $remainder < 2 ? $remainder : 11 - $remainder;
    }

    /**
     * Acepta puntos, espacios y guión. Devuelve "#########-D" o null si el
     * DV no coincide, o si la longitud de la base está fuera de rango.
     */
    public static function normalize(string $input): ?string
    {
        $digits = preg_replace('/\D/', '', $input) ?? '';
        if (strlen($digits) < self::MIN_BASE_LENGTH + 1) {
            return null;
        }
        $base = substr($digits, 0, -1);
        $providedDv = (int) substr($digits, -1);
        if (strlen($base) < self::MIN_BASE_LENGTH || strlen($base) > self::MAX_BASE_LENGTH) {
            return null;
        }
        if (self::checkDigit($base) !== $providedDv) {
            return null;
        }

        return $base.'-'.$providedDv;
    }
}
