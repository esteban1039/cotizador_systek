<?php

namespace App\Repositories\Eloquent;

use App\Models\CatalogItem;
use App\Models\CommercialRule;
use App\Models\PriceVersion;
use App\Repositories\Contracts\PricingRepository;

final class EloquentPricingRepository implements PricingRepository
{
    public function lockedPrice(string $id): array
    {
        $price = PriceVersion::query()->whereKey($id)->toBase()->first();
        $item = $price ? CatalogItem::query()->whereKey($price->catalog_item_id)->sharedLock()->toBase()->first() : null;
        $price = $price ? PriceVersion::query()->whereKey($id)->sharedLock()->toBase()->first() : null;

        return [$price, $item];
    }

    public function lockedRule(string $family): ?object
    {
        return CommercialRule::query()->where('family', $family)->sharedLock()->toBase()->first();
    }
}
