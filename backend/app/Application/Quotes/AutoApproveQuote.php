<?php

namespace App\Application\Quotes;

use App\Domain\Audit;
use App\Domain\Quotes\ApprovalValidation;
use App\Domain\Quotes\SnapshotCompatibility;
use App\Models\User;
use App\Repositories\Contracts\QuoteRepository;
use Illuminate\Support\Facades\DB;

/**
 * Excepción decidida por el dueño del producto: una cotización montada por un administrador queda aprobada
 * internamente al guardarse (no emitida). Debe invocarse dentro de la transacción de CreateQuote; si la
 * validación bloquea lanza ValidationException y toda la transacción (incluida la cotización) se revierte.
 * El rol lo decide solo el servidor; ningún campo de la petición puede activarlo.
 */
final class AutoApproveQuote
{
    public function __construct(
        private QuoteRepository $quotes,
        private ApprovalValidation $validation,
        private FreeLineItemCreator $items,
        private RecordQuoteKnowledge $knowledge,
    ) {}

    public static function applies(User $actor): bool
    {
        return $actor->role === 'admin';
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return list<array{free_line_id: string, catalog_item_id: string, sku: string, price_version_id: string}>
     */
    public function execute(string $id, User $actor, array $snapshot): array
    {
        abort_unless(self::applies($actor), 403);
        $quote = $this->quotes->findForReview($id);
        abort_unless($quote, 404);
        $normalized = SnapshotCompatibility::normalize($snapshot, $quote);
        $freeLines = array_values(array_filter($normalized['lines'], fn (array $line): bool => ($line['line_type'] ?? 'catalog') === 'free'));

        $checked = $this->validation->check($snapshot, $quote);
        $this->quotes->transition($id, 'approved', [
            'quote_id' => $id, 'user_id' => $actor->id, 'decision' => 'approve', 'auto_approved' => true,
            'reason' => 'Auto-aprobada: cotización creada por un administrador.',
            'validation' => json_encode($checked, JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);

        $created = [];
        foreach ($freeLines as $line) {
            $created[] = $this->items->create($id, $actor, $line, (string) $normalized['family'], 'admin_auto_approval');
        }
        Audit::record($actor->id, 'quote.auto_approved', $id, [
            'quote_id' => $id, 'created_item_ids' => array_column($created, 'catalog_item_id'),
        ]);

        $actorId = $actor->id;
        DB::afterCommit(function () use ($id, $actorId): void {
            try {
                $this->knowledge->execute($id, $actorId);
            } catch (\Throwable) {
                try {
                    Audit::record($actorId, 'knowledge.capture_failed', $id, []);
                } catch (\Throwable) {
                }
            }
        });

        return $created;
    }
}
