<?php

namespace App\Application\Quotes;

use App\Domain\Quotes\KnowledgeEntry;
use App\Repositories\Contracts\KnowledgeRepository;

/**
 * Evaluación offline (§9.4): por cada cotización aprobada, consulta la base excluyéndola y mide hit@k
 * (misma familia y al menos un SKU en común). No llama al proveedor ni escribe datos.
 */
final class EvaluateKnowledge
{
    public function __construct(private KnowledgeRepository $knowledge, private KnowledgeEntry $entries) {}

    /**
     * @return array{evaluated: int, hits: int, hit_at_k: float, k: int}
     */
    public function handle(int $k): array
    {
        $evaluated = $hits = 0;
        foreach ($this->knowledge->approvedSnapshots() as $row) {
            $ids = array_values(array_filter(array_column((array) ($row['snapshot']['lines'] ?? []), 'catalog_item_id')));
            $entry = $this->entries->fromSnapshot($row['snapshot'], $this->knowledge->catalogByIds($ids), $this->knowledge->freeLineCatalog($row['quote_id']));
            $skus = array_values(array_filter(array_column($entry['lines'], 'sku')));
            if ($skus === [] || trim((string) $entry['requirement_text']) === '') {
                continue;
            }
            $evaluated++;
            foreach ($this->knowledge->similar((string) $entry['requirement_text'].' '.($entry['lines_text'] ?? ''), $entry['family'], $k, $row['root_id']) as $precedent) {
                $shared = array_intersect($skus, array_filter(array_column($precedent['lines'], 'sku')));
                if ($precedent['family'] === $entry['family'] && $shared !== []) {
                    $hits++;

                    continue 2;
                }
            }
        }

        return ['evaluated' => $evaluated, 'hits' => $hits, 'hit_at_k' => $evaluated === 0 ? 0.0 : round($hits / $evaluated, 3), 'k' => $k];
    }
}
