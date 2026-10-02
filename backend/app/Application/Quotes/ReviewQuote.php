<?php

namespace App\Application\Quotes;

use App\Domain\Audit;
use App\Domain\Catalog\FreeLineSku;
use App\Domain\Quotes\ApprovalValidation;
use App\Domain\Quotes\SnapshotCompatibility;
use App\Models\User;
use App\Repositories\Contracts\CatalogRepository;
use App\Repositories\Contracts\QuoteRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Revisión (aprobar/devolver) de una cotización en revisión. Al aprobar, cada línea libre crea su ítem activo
 * y su precio v1 dentro de la misma transacción (docs/diseno-lineas-libres.md §6). Nunca toca la instantánea.
 */
final class ReviewQuote
{
    public function __construct(
        private QuoteRepository $quotes,
        private CatalogRepository $catalog,
        private ApprovalValidation $validation,
        private RecordQuoteKnowledge $knowledge,
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

            $checked = $approve ? $this->validation->check($snapshot, $quote) : [];
            $status = $approve ? 'approved' : 'draft';
            $this->quotes->transition($id, $status, [
                'quote_id' => $id, 'user_id' => $actor->id, 'decision' => $decision,
                'reason' => $reason, 'validation' => json_encode($checked, JSON_THROW_ON_ERROR), 'created_at' => now(),
            ]);
            Audit::record($actor->id, 'quote.'.$decision, $id, ['status' => $status, 'reason' => $reason]);

            $created = [];
            foreach ($freeLines as $line) {
                $created[] = $this->createFromLine($id, $actor, $line, (string) $normalized['family']);
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

    /**
     * @param  array<string, mixed>  $line
     * @return array{free_line_id: string, catalog_item_id: string, sku: string, price_version_id: string}
     */
    private function createFromLine(string $quoteId, User $actor, array $line, string $quoteFamily): array
    {
        $family = (string) ($line['family'] ?? $quoteFamily);
        $freeLineId = (string) $line['free_line_id'];
        $length = 8;
        $sku = FreeLineSku::for($family, $freeLineId, $length);
        while ($this->catalog->skuExists($sku) && $length < 32) {
            $length += 4;
            $sku = FreeLineSku::for($family, $freeLineId, $length);
        }

        $itemId = (string) Str::uuid();
        $this->catalog->createItem([
            'id' => $itemId, 'sku' => $sku, 'description' => (string) $line['description'], 'family' => $family,
            'unit' => (string) $line['unit'], 'active' => true, 'is_demo' => false,
        ]);
        $priceId = (string) Str::uuid();
        $today = now()->toDateString();
        $price = $this->catalog->publishPrice($itemId, [
            'id' => $priceId, 'price_cents' => (int) $line['price_cents'], 'cost_cents' => (int) $line['cost_cents'], 'tax_bps' => (int) $line['tax_bps'],
            'valid_from' => $today, 'valid_until' => now()->addDays((int) config('catalog.free_line_price_days', 90))->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        abort_if($price === null, 500, 'No se pudo crear el precio del ítem.');
        $this->quotes->linkFreeLine([
            'quote_id' => $quoteId, 'free_line_id' => $freeLineId, 'catalog_item_id' => $itemId,
            'price_version_id' => $priceId, 'approved_by' => $actor->id, 'created_at' => now(),
        ]);

        $origin = ['origin' => 'quote_approval', 'quote_id' => $quoteId, 'free_line_id' => $freeLineId];
        Audit::record($actor->id, 'catalog.created_from_quote', $itemId, $origin + ['version' => (int) $price['version']]);
        Audit::record($actor->id, 'price.published', $priceId, $origin + ['catalog_item_id' => $itemId, 'version' => (int) $price['version']]);

        return ['free_line_id' => $freeLineId, 'catalog_item_id' => $itemId, 'sku' => $sku, 'price_version_id' => $priceId];
    }
}
