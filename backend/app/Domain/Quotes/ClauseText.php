<?php

namespace App\Domain\Quotes;

/**
 * Normalización, hash y render del texto de cláusulas. Sin motor de
 * plantillas: el único marcador permitido es `{vigencia_dias}`, sustituido
 * literalmente.
 */
final class ClauseText
{
    public static function normalize(string $body): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/', ' ', $body)));
    }

    public static function hash(string $body): string
    {
        return hash('sha256', self::normalize($body));
    }

    public static function render(string $body, int $validityDays): string
    {
        return str_replace('{vigencia_dias}', (string) $validityDays, $body);
    }

    /**
     * Errores de contenido al publicar: tokens distintos de `{vigencia_dias}`
     * y secuencias de 8 o más dígitos (posibles números de cuenta bancaria).
     *
     * @return list<string>
     */
    public static function validate(string $body): array
    {
        $errors = [];
        if (preg_match_all('/\{([^{}]*)\}/', $body, $matches) && array_diff($matches[1], ['vigencia_dias']) !== []) {
            $errors[] = 'El texto solo admite el marcador {vigencia_dias}.';
        }
        if (self::looksLikeBankAccount($body)) {
            $errors[] = 'Los datos bancarios se configuran en Empresa emisora, no en cláusulas.';
        }

        return $errors;
    }

    /** Secuencia de 8 o más dígitos (ignorando espacios, puntos y guiones). */
    public static function looksLikeBankAccount(string $text): bool
    {
        $digitsOnly = preg_replace('/[\s.\-]/', '', $text) ?? $text;

        return (bool) preg_match('/\d{8,}/', $digitsOnly);
    }
}
