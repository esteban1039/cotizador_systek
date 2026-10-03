<?php

namespace App\Application\Quotes;

use App\Domain\Audit;
use App\Domain\Catalog\FreeLineSku;
use App\Models\User;
use App\Repositories\Contracts\CatalogRepository;
use App\Repositories\Contracts\QuoteRepository;
use Illuminate\Support\Str;

/**
 * Crea el ítem activo, su precio v1 y el vínculo de una línea libre. Debe invocarse dentro de la transacción
 * del caso de uso (aprobación manual o auto-aprobación de administrador).
 */
final class FreeLineItemCreator
{
    public function __construct(private QuoteRepository $quotes, private CatalogRepository $catalog) {}

    /**
     * @param  array<string, mixed>  $line
     * @return array{free_line_id: string, catalog_item_id: string, sku: string, price_version_id: string}
     */
    public function create(string $quoteId, User $actor, array $line, string $quoteFamily, string $origin): array
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

        $origin = ['origin' => $origin, 'quote_id' => $quoteId, 'free_line_id' => $freeLineId];
        Audit::record($actor->id, 'catalog.created_from_quote', $itemId, $origin + ['version' => (int) $price['version']]);
        Audit::record($actor->id, 'price.published', $priceId, $origin + ['catalog_item_id' => $itemId, 'version' => (int) $price['version']]);

        return ['free_line_id' => $freeLineId, 'catalog_item_id' => $itemId, 'sku' => $sku, 'price_version_id' => $priceId];
    }
}
