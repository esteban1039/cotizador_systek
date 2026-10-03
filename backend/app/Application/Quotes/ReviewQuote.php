<?php

namespace App\Application\Quotes;

use App\Domain\Audit;
use App\Domain\Quotes\ApprovalValidation;
use App\Domain\Quotes\SnapshotCompatibility;
use App\Models\User;
use App\Repositories\Contracts\CatalogRepository;
use App\Repositories\Contracts\QuoteRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Revisión (aprobar/devolver) de una cotización en revisión. Al aprobar, cada línea libre crea su ítem activo
 * y su precio v1 dentro de la misma transacción (docs/diseno-lineas-libres.md §6). Nunca toca la instantánea.
 */
final class ReviewQuote
{
    public function __construct(
        private QuoteRepository $quotes,
        private FreeLineItemCreator $items,
        private ApprovalValidation $validation,
        private RecordQuoteKnowledge $knowledge,
        private CatalogRepository $catalog,
    ) {}

    /**
     * @param  list<string>|null  $confirmNewItems  `free_line_id` de las líneas libres que se crearán.
     * @return array{status: string, validation: array<string, mixed>, emission_allowed: false, created_items: list<array{free_line_id: string, catalog_item_id: string, sku: string, price_version_id: string}>}
     */
    public function execute(string $id, User $actor, string $decision, string $reason, ?array $confirmNewItems): array
    {
        return DB::transaction(function () use ($id, $actor, $decision, $reason, $confirmNewItems): array {
            $quote = $this->quotes->findForReview($id);
            abort_unless($quote, 404);
            abort_unless($quote->status === 'in_review', 409, 'La cotización no está pendiente de revisión.');
            abort_if((int) $quote->created_by === $actor->id, 403, 'Otra persona debe revisar tu cotización.');
            $snapshot = json_decode($quote->snapshot, true, flags: JSON_THROW_ON_ERROR);
            $approve = $decision === 'approve';

            $freeLines = [];
            $normalized = ['family' => ''];
            if ($approve) {
                $normalized = SnapshotCompatibility::normalize($snapshot, $quote);
                $freeLines = array_values(array_filter($normalized['lines'], fn (array $line): bool => ($line['line_type'] ?? 'catalog') === 'free'));
                $expected = array_column($freeLines, 'free_line_id');
                $given = $confirmNewItems ?? [];
                sort($expected);
                sort($given);
                if ($freeLines !== [] && $expected !== $given) {
                    throw ValidationException::withMessages(['confirm_new_items' => 'Confirma los ítems que se crearán']);
                }
            }

            // Orden: cotización (ya bloqueada) -> familia (advisory, igual que CreateQuote) -> ítem -> precio.
            $this->catalog->lockFreeLineFamilies(array_map(fn (array $line): string => (string) ($line['family'] ?? $normalized['family']), $freeLines));

            $checked = $approve ? $this->validation->check($snapshot, $quote) : [];
            $status = $approve ? 'approved' : 'draft';
            $this->quotes->transition($id, $status, [
                'quote_id' => $id, 'user_id' => $actor->id, 'decision' => $decision,
                'reason' => $reason, 'validation' => json_encode($checked, JSON_THROW_ON_ERROR), 'created_at' => now(),
            ]);
            Audit::record($actor->id, 'quote.'.$decision, $id, ['status' => $status, 'reason' => $reason]);

            $created = [];
            foreach ($freeLines as $line) {
                $created[] = $this->items->create($id, $actor, $line, (string) $normalized['family'], 'quote_approval');
            }

            if ($approve) {
                $actorId = $actor->id;
                // Captura de conocimiento solo tras el commit; un fallo se audita sin datos y no deshace la aprobación.
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
            }

            return ['status' => $status, 'validation' => $checked, 'emission_allowed' => false, 'created_items' => $created];
        });
    }
}
