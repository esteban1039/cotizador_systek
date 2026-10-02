<?php

namespace App\Domain\Quotes;

/**
 * Depurador de datos para la base de conocimiento (docs/diseno-base-conocimiento-ia.md §8.3).
 * Puro: sin acceso a datos. Elimina lo que identifica al cliente o es sensible y devuelve
 * banderas y un nivel de riesgo; con `review` la entrada no se usa hasta que un admin la active.
 */
final class KnowledgeScrubber
{
    /** Reglas cuya activación exige revisión humana aunque el texto ya esté limpio. */
    private const STRONG_FLAGS = ['email_removed', 'phone_removed', 'nit_removed', 'account_removed'];

    /** Palabras genéricas de un nombre comercial que no delatan al cliente por sí solas. */
    private const GENERIC_WORDS = [
        'supermercado', 'supermercados', 'tienda', 'parroquia', 'institucion', 'educativa', 'hospital', 'fundacion',
        'edificio', 'urbanizacion', 'grupo', 'comercializadora', 'inversiones', 'industrias', 'fondo', 'empleados',
        'casa', 'sede', 'principal', 'bodega', 'oficina', 'local', 'punto', 'planta', 'centro', 'servicios',
        'asesorias', 'contables', 'estadero', 'parqueadero', 'licorera', 'super', 'remates', 'finca',
    ];

    /**
     * @param  list<string|null>  $names  Nombres de cliente/sede a eliminar.
     * @return array{text: string, flags: list<string>, risk: string}
     */
    public function scrub(string $text, array $names = []): array
    {
        $flags = [];
        $text = $this->stripInvisible($text, $flags);

        $text = $this->replace('/<\/?[A-Za-z_][\w:-]*[^<>]{0,80}>/u', $text, $flags, 'tag_removed');
        $text = $this->replace('/[\p{L}\p{N}._%+\-]+@[\p{L}\p{N}.\-]+\.[\p{L}]{2,}/u', $text, $flags, 'email_removed');
        $text = $this->replace('/\b(?:COP|USD)\s*\$?\s*\d[\d.,]*|\$\s*\d[\d.,]*(?:\s*(?:COP|USD|M|mm|millones))?/iu', $text, $flags, 'amount_removed');
        $text = $this->replace('/\bNIT\b[\s.:#-]*\d[\d.\- ]*\d|(?<![\p{N}])\d{1,3}(?:\.\d{3}){2}(?:-\d)?(?![\p{N}])|(?<![\p{N}])\d{9}-\d(?![\p{N}])/iu', $text, $flags, 'nit_removed');
        $text = $this->replace('/(?<![\p{N}])(?:\+?57[\s.\-]?)?\(?60\d\)?[\s.\-]?\d{3}[\s.\-]?\d{4}(?![\p{N}])|(?<![\p{N}])(?:\+?57[\s.\-]?)?3\d{2}[\s.\-]?\d{3}[\s.\-]?\d{4}(?![\p{N}])/u', $text, $flags, 'phone_removed');
        $text = $this->replace('/(?<![\p{N}])(?:\p{Nd}[ \-.\/_,·]?){8,}/u', $text, $flags, 'account_removed');
        $text = $this->replace('/(?<![\p{N}])\p{Nd}{7,}(?![\p{N}])/u', $text, $flags, 'long_number_removed');

        $tokens = [];
        foreach ($names as $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }
            foreach ($this->variants($name) as $variant) {
                $pattern = '/(?<![\p{L}\p{N}])'.$this->accentInsensitive($variant).'(?![\p{L}\p{N}])/iu';
                $text = $this->replace($pattern, $text, $flags, 'client_name_removed');
            }
            array_push($tokens, ...$this->distinctiveTokens($name));
        }

        $text = trim((string) preg_replace('/[ \t\x{00A0}]{2,}/u', ' ', $text));
        $text = trim((string) preg_replace('/\s*\n\s*/u', "\n", $text));

        $residual = $this->residual($text, $tokens);
        array_push($flags, ...$residual);
        $flags = array_values(array_unique($flags));
        $risk = ($residual !== [] || array_intersect($flags, self::STRONG_FLAGS) !== []) ? 'review' : 'none';

        return ['text' => $text, 'flags' => $flags, 'risk' => $risk];
    }

    /**
     * @param  list<string>  $flags
     */
    private function stripInvisible(string $text, array &$flags): string
    {
        $clean = preg_replace('/[\p{Cc}\p{Cf}\p{Co}\x{2028}\x{2029}]/u', ' ', $text);
        $clean = $clean === null ? '' : preg_replace('/ {2,}/', ' ', $clean);
        $clean = (string) $clean;
        // Los saltos de línea y tabulaciones legítimos se conservan como espacio, no como riesgo.
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]|[\p{Cf}\p{Co}]/u', $text)) {
            $flags[] = 'control_removed';
        }

        return $clean;
    }

    /**
     * @param  list<string>  $flags
     */
    private function replace(string $pattern, string $text, array &$flags, string $flag): string
    {
        $count = 0;
        $result = preg_replace($pattern, ' ', $text, -1, $count);
        if ($result === null) {
            return $text;
        }
        if ($count > 0) {
            $flags[] = $flag;
        }

        return $result;
    }

    /**
     * Nombre completo y sus variantes sin sufijo societario y sin paréntesis, de mayor a menor longitud.
     *
     * @return list<string>
     */
    private function variants(string $name): array
    {
        $candidates = [$name];
        $withoutParentheses = trim((string) preg_replace('/\([^)]*\)/u', ' ', $name));
        $candidates[] = $withoutParentheses;
        if (preg_match_all('/\(([^)]+)\)/u', $name, $matches)) {
            array_push($candidates, ...$matches[1]);
        }
        foreach ($candidates as $candidate) {
            $stripped = $this->stripCorporateSuffix($candidate);
            $candidates[] = $stripped;
        }
        $variants = [];
        foreach ($candidates as $candidate) {
            $candidate = trim(preg_replace('/\s+/u', ' ', $candidate) ?? $candidate);
            if (mb_strlen($candidate) >= 3) {
                $variants[mb_strtolower($candidate)] = $candidate;
            }
        }
        $variants = array_values($variants);
        usort($variants, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return $variants;
    }

    private function stripCorporateSuffix(string $name): string
    {
        $pattern = '/[\s,]+(?:S\.?\s?A\.?\s?S\.?|S\.?\s?A\.?|LTDA\.?|LIMITADA|E\.?\s?U\.?|Y\s+CIA\.?|&\s*CIA\.?)\s*$/iu';
        do {
            $previous = $name;
            $name = trim((string) preg_replace($pattern, '', $name));
        } while ($name !== $previous);

        return $name;
    }

    /** Patrón que ignora mayúsculas y acentos y tolera espacios variables. */
    private function accentInsensitive(string $text): string
    {
        $classes = [
            'a' => 'aáàäâã', 'e' => 'eéèëê', 'i' => 'iíìïî', 'o' => 'oóòöôõ', 'u' => 'uúùüû', 'n' => 'nñ', 'c' => 'cç',
        ];
        $pattern = '';
        foreach (mb_str_split(mb_strtolower($text)) as $char) {
            $base = $this->stripAccent($char);
            if (isset($classes[$base])) {
                $pattern .= '['.$classes[$base].mb_strtoupper($classes[$base]).']';
            } elseif (preg_match('/\s/u', $char)) {
                $pattern .= '\s+';
            } else {
                $pattern .= preg_quote($char, '/');
            }
        }

        return $pattern;
    }

    private function stripAccent(string $char): string
    {
        $map = ['á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
            'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i', 'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u', 'ñ' => 'n', 'ç' => 'c'];

        return $map[$char] ?? $char;
    }

    /**
     * Palabras de 5 letras o más del nombre (sin sufijos societarios ni palabras genéricas).
     *
     * @return list<string>
     */
    private function distinctiveTokens(string $name): array
    {
        $tokens = [];
        foreach ($this->variants($name) as $variant) {
            foreach (preg_split('/[^\p{L}\p{N}]+/u', $variant, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                $folded = $this->fold($word);
                if (mb_strlen($folded) >= 5 && ! in_array($folded, self::GENERIC_WORDS, true)) {
                    $tokens[$folded] = $folded;
                }
            }
        }

        return array_values($tokens);
    }

    private function fold(string $word): string
    {
        $word = mb_strtolower($word);

        return implode('', array_map(fn (string $char): string => $this->stripAccent($char), mb_str_split($word)));
    }

    /**
     * Restos sospechosos tras limpiar.
     *
     * @param  list<string>  $tokens
     * @return list<string>
     */
    private function residual(string $text, array $tokens): array
    {
        $flags = [];
        $folded = $this->fold($text);
        foreach ($tokens as $token) {
            if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($token, '/').'/u', $folded)) {
                $flags[] = 'client_token_remaining';
                break;
            }
        }
        if (preg_match('/@|https?:|www\./i', $text) || ClauseText::looksLikeBankAccount($text) || preg_match('/\p{Nd}{6,}/u', $text)) {
            $flags[] = 'sensitive_remaining';
        }

        return $flags;
    }
}
