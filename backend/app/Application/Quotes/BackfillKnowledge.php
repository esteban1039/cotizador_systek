<?php

namespace App\Application\Quotes;

use App\Domain\Audit;
use App\Domain\Quotes\KnowledgeEntry;
use App\Repositories\Contracts\KnowledgeRepository;
use Illuminate\Support\Facades\DB;

/**
 * Relleno de cotizaciones aprobadas/emitidas existentes (§8.6). Idempotente; guarda el precio de referencia
 * de la línea aprobada (interno, nunca enviado a Anthropic). Auditoría solo con contadores.
 */
final class BackfillKnowledge
{
    public function __construct(private KnowledgeRepository $knowledge, private KnowledgeEntry $entries) {}

    /**
     * @return array<string, int>
     */
    public function handle(bool $dryRun): array
    {
        $summary = ['quotes' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'active' => 0, 'needs_review' => 0];
        foreach ($this->knowledge->approvedSnapshots() as $row) {
            $ids = array_values(array_filter(array_column((array) ($row['snapshot']['lines'] ?? []), 'catalog_item_id')));
            $entry = $this->entries->fromSnapshot($row['snapshot'], $this->knowledge->catalogByIds($ids), $this->knowledge->freeLineCatalog($row['quote_id']));
            $summary['quotes']++;
            $summary[$entry['risk'] === 'review' ? 'needs_review' : 'active']++;
            if ($dryRun) {
                continue;
            }
            $result = DB::transaction(fn (): string => $this->knowledge->upsertFromQuote($entry + [
                'source' => 'approved_quote', 'source_root_quote_id' => $row['root_id'], 'source_revision' => $row['revision'],
                'source_ref' => null, 'issued' => $row['issued'], 'trust' => $row['issued'] ? '1.10' : '1.00',
            ]));
            $summary[$result]++;
        }
        if (! $dryRun) {
            Audit::record(null, 'knowledge.backfilled', 'backfill', $summary);
        }

        return $summary;
    }
}
