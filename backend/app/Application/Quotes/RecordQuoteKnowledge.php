<?php

namespace App\Application\Quotes;

use App\Domain\Audit;
use App\Domain\Quotes\AssistDiff;
use App\Domain\Quotes\KnowledgeEntry;
use App\Repositories\Contracts\KnowledgeRepository;
use Illuminate\Support\Facades\DB;

/**
 * Captura continua (docs/diseno-base-conocimiento-ia.md §8): al aprobar una cotización guarda una entrada por raíz.
 * Se ejecuta DESPUÉS del commit de la aprobación; un fallo nunca la revierte (el llamador lo audita).
 */
final class RecordQuoteKnowledge
{
    public function __construct(private KnowledgeRepository $knowledge, private KnowledgeEntry $entries) {}

    /**
     * @return 'created'|'updated'|'unchanged'|'skipped'
     */
    public function execute(string $quoteId, ?int $actorId): string
    {
        $quote = $this->knowledge->approvedQuote($quoteId);
        if ($quote === null) {
            return 'skipped';
        }
        $snapshot = $quote['snapshot'];
        $ids = array_values(array_filter(array_column((array) ($snapshot['lines'] ?? []), 'catalog_item_id')));
        $entry = $this->entries->fromSnapshot($snapshot, $this->knowledge->catalogByIds($ids), $this->knowledge->freeLineCatalog($quote['quote_id']));
        if (! config('ai_assistant.knowledge.auto_activate')) {
            $entry['risk'] = 'review';
        }

        $extra = [];
        $assist = $this->knowledge->assistRequestForRoot($quote['root_id']);
        $counts = null;
        if ($assist !== null) {
            $diff = AssistDiff::compare($assist['proposed_lines'], $entry['lines']);
            $extra = ['ai_assisted' => true, 'human_edit_ratio' => $diff['human_edit_ratio']];
            $counts = ['kept' => $diff['kept'], 'qty_changed' => $diff['qty_changed'], 'removed' => $diff['removed'], 'added' => $diff['added']];
        }

        $result = DB::transaction(function () use ($quote, $entry, $extra, $assist, $counts): string {
            $result = $this->knowledge->upsertFromQuote($entry + $extra + [
                'source' => 'approved_quote', 'source_root_quote_id' => $quote['root_id'], 'source_revision' => $quote['revision'],
                'source_ref' => null, 'issued' => $quote['issued'], 'trust' => $quote['issued'] ? '1.10' : '1.00',
            ]);
            if ($assist !== null && $counts !== null) {
                $this->knowledge->recordAssistOutcome($assist['id'], $counts);
            }

            return $result;
        });
        Audit::record($actorId, 'knowledge.captured', $quoteId, ['result' => $result, 'ai_assisted' => $assist !== null, 'needs_review' => $entry['risk'] === 'review']);

        return $result;
    }
}
