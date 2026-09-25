<?php

namespace App\Repositories\Eloquent;

use App\Models\CatalogItem;
use App\Models\CommercialRule;
use App\Models\PriceVersion;
use App\Repositories\Contracts\CatalogRepository;
use Illuminate\Support\Collection;

final class EloquentCatalogRepository implements CatalogRepository
{
    public function currentPrices(string $date): Collection
    {
        return CatalogItem::query()
            ->join('price_versions', 'price_versions.catalog_item_id', '=', 'catalog_items.id')
            ->where('catalog_items.active', true)->where('price_versions.status', 'approved')
            ->where('valid_from', '<=', $date)->where('valid_until', '>=', $date)
            ->select('catalog_items.*', 'price_versions.id as price_version_id', 'version', 'price_cents', 'tax_bps', 'valid_until')
            ->orderBy('sku')->get()->map(fn (CatalogItem $item): array => $item->getAttributes());
    }

    public function catalogWithHistory(): Collection
    {
        return CatalogItem::query()->with('versions')->orderBy('sku')->get()
            ->map(fn (CatalogItem $item): array => array_merge($item->getAttributes(), [
                'versions' => $item->versions->map(fn (PriceVersion $version): array => $version->getAttributes())->all(),
            ]));
    }

    public function skuExists(string $sku): bool
    {
        return CatalogItem::query()->where('sku', $sku)->exists();
    }

    public function createItem(array $attributes): array
    {
        return CatalogItem::query()->create($attributes)->fresh()->getAttributes();
    }

    public function publishPrice(string $itemId, array $attributes): ?array
    {
        $item = CatalogItem::query()->whereKey($itemId)->lockForUpdate()->first();
        if (! $item) {
            return null;
        }

        $version = (int) PriceVersion::query()->where('catalog_item_id', $itemId)->max('version') + 1;
        PriceVersion::query()->where('catalog_item_id', $itemId)->where('status', 'approved')
            ->update(['status' => 'historical', 'updated_at' => now()]);
        $record = array_merge($attributes, ['catalog_item_id' => $itemId, 'version' => $version, 'status' => 'approved']);
        PriceVersion::query()->create($record);

        return $record;
    }

    public function setActive(string $itemId, bool $active): bool
    {
        $item = CatalogItem::query()->whereKey($itemId)->lockForUpdate()->first();
        if (! $item) {
            return false;
        }
        $item->active = $active;
        $item->save();

        return true;
    }

    public function commercialRules(): Collection
    {
        return CommercialRule::query()->orderBy('family')->get()
            ->map(fn (CommercialRule $rule): array => $rule->getAttributes());
    }

    public function saveCommercialRule(string $family, array $values): void
    {
        CommercialRule::query()->upsert([array_merge($values, ['family' => $family, 'created_at' => now()])], ['family'], array_keys($values));
    }
}
