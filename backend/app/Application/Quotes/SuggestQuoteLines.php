<?php

namespace App\Application\Quotes;

use App\Repositories\Contracts\KnowledgeRepository;

/** Sugiere partidas desde la base de conocimiento, sin IA ni catálogo, fragmento por fragmento. */
final class SuggestQuoteLines
{
    private const MAX_FRAGMENTS = 12;

    private const PER_FRAGMENT = 5;

    private const MAX_TOTAL = 30;

    public function __construct(private readonly KnowledgeRepository $knowledge) {}

    /**
     * @return list<array{fragment: string, matches: list<array<string, mixed>>}>
     */
    public function execute(string $text, ?string $family): array
    {
        $clean = trim((string) preg_replace('/[^\P{C}\n]+/u', ' ', $text));
        $fragments = [];
        foreach (preg_split('/[,;\n]+/u', $clean) ?: [] as $part) {
            $part = trim((string) preg_replace('/\s+/u', ' ', $part));
            $length = mb_strlen($part);
            if ($length >= 3 && $length <= 200 && ! in_array($part, $fragments, true)) {
                $fragments[] = $part;
            }
            if (count($fragments) >= self::MAX_FRAGMENTS) {
                break;
            }
        }
        if ($fragments === []) {
            $whole = trim((string) preg_replace('/\s+/u', ' ', $clean));
            if (mb_strlen($whole) < 3) {
                return [];
            }
            $fragments = [mb_substr($whole, 0, 200)];
        }

        return $this->knowledge->suggestLines($fragments, $family, self::PER_FRAGMENT, self::MAX_TOTAL);
    }
}
