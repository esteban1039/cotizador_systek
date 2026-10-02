<?php

namespace App\Repositories\Eloquent;

use App\Domain\Catalog\FreeLineSku;
use App\Domain\DecimalMoney;
use App\Models\CatalogItem;
use App\Models\CommercialRule;
use App\Models\PriceVersion;
use App\Repositories\Contracts\CatalogRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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

    public function assistantCatalog(string $date, ?string $family, int $limit, array $prioritySkus = []): array
    {
        $base = fn () => CatalogItem::query()
            ->join('price_versions', 'price_versions.catalog_item_id', '=', 'catalog_items.id')
            ->where('catalog_items.active', true)->where('price_versions.status', 'approved')
            ->where('valid_from', '<=', $date)->where('valid_until', '>=', $date)
            ->select('catalog_items.sku', 'catalog_items.description', 'catalog_items.family', 'catalog_items.unit', 'price_versions.id as price_version_id');
        $first = $prioritySkus === [] ? collect() : $base()->whereIn('catalog_items.sku', array_slice($prioritySkus, 0, $limit))->orderBy('catalog_items.sku')->get();
        $order = array_flip($prioritySkus);
        $first = $first->sortBy(fn ($row): int => $order[(string) $row->sku] ?? PHP_INT_MAX)->values();
        $rest = $base()->when($family !== null, fn ($query) => $query->where('catalog_items.family', $family))
            ->when($first->isNotEmpty(), fn ($query) => $query->whereNotIn('catalog_items.sku', $first->pluck('sku')->all()))
            ->orderBy('catalog_items.sku')->limit($limit + 1)->get();
        $room = max(0, $limit - $first->count());
        $max = (int) config('ai_assistant.max_description_chars');

        return [
            'items' => $first->concat($rest->take($room))->map(fn ($row): array => [
                'sku' => (string) $row->sku, 'description' => mb_substr((string) $row->description, 0, $max),
                'family' => (string) $row->family, 'unit' => (string) $row->unit, 'price_version_id' => (string) $row->price_version_id,
            ])->values()->all(),
            'truncated' => $rest->count() > $room,
        ];
    }

    public function similarItems(string $q, ?string $family, int $limit): array
    {
        $today = now()->toDateString();
        $columns = ['catalog_items.id', 'catalog_items.sku', 'catalog_items.description', 'catalog_items.unit', 'catalog_items.family',
            'price_versions.id as price_version_id', 'price_versions.price_cents', 'price_versions.valid_until'];
        $base = CatalogItem::query()
            ->join('price_versions', 'price_versions.catalog_item_id', '=', 'catalog_items.id')
            ->where('catalog_items.active', true)->where('price_versions.status', 'approved')
            ->where('price_versions.valid_from', '<=', $today)->where('price_versions.valid_until', '>=', $today)
            ->when($family !== null, fn ($query) => $query->where('catalog_items.family', $family));
        $text = mb_substr(trim($q), 0, 120);
        if ($text === '' || $limit < 1) {
            return [];
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            $score = 'GREATEST(similarity(quote_knowledge_unaccent(lower(catalog_items.description)), quote_knowledge_unaccent(lower(?))),'
                .' word_similarity(quote_knowledge_unaccent(lower(?)), quote_knowledge_unaccent(lower(catalog_items.description))))';
            $rows = $base->select($columns)->selectRaw("{$score} as score", [$text, $text])
                ->whereRaw("{$score} >= 0.2", [$text, $text])->orderByDesc('score')->orderBy('catalog_items.sku')->limit($limit)->get();
        } else {
            $terms = array_values(array_unique(array_filter(preg_split('/\s+/u', mb_strtolower($text)) ?: [], fn (string $term): bool => mb_strlen($term) >= 2)));
            if ($terms === []) {
                return [];
            }
            $like = fn (string $term): string => '%'.addcslashes($term, '%_\\').'%';
            $rows = $base->select($columns)->where(function ($query) use ($terms, $like): void {
                foreach ($terms as $term) {
                    $query->orWhereRaw("lower(catalog_items.description) LIKE ? ESCAPE '\\'", [$like($term)]);
                }
            })->limit(200)->get()->map(function ($row) use ($terms): object {
                $haystack = mb_strtolower((string) $row->description);
                $row->score = count(array_filter($terms, fn (string $term): bool => str_contains($haystack, $term))) / count($terms);

                return $row;
            })->sortBy([['score', 'desc'], ['sku', 'asc']])->take($limit)->values();
        }

        return $rows->map(fn ($row): array => [
            'id' => (string) $row->id, 'sku' => (string) $row->sku, 'description' => (string) $row->description,
            'unit' => (string) $row->unit, 'family' => (string) $row->family, 'price_version_id' => (string) $row->price_version_id,
            'price' => DecimalMoney::format((int) $row->price_cents), 'valid_until' => substr((string) $row->valid_until, 0, 10),
            'score' => round((float) $row->score, 3),
        ])->all();
    }

    public function catalogWithHistory(): Collection
    {
        return CatalogItem::query()->with('versions')->orderBy('sku')->get()
            ->map(fn (CatalogItem $item): array => array_merge($item->getAttributes(), [
                'versions' => $item->versions->map(fn (PriceVersion $version): array => $version->getAttributes())->all(),
            ]));
    }

    public function skusByIds(array $ids): array
    {
        return CatalogItem::query()->whereIn('id', $ids)->pluck('sku', 'id')->map(fn ($sku): string => (string) $sku)->all();
    }

    public function skuExists(string $sku): bool
    {
        return CatalogItem::query()->where('sku', $sku)->exists();
    }

    public function activeExactMatch(string $description, string $family): ?array
    {
        $wanted = FreeLineSku::normalize($description);
        $match = CatalogItem::query()->where('active', true)->where('family', $family)
            ->whereRaw('lower(trim(description)) = ?', [$wanted])->orderBy('sku')->first(['id', 'sku']);

        return $match === null ? null : ['id' => $match->id, 'sku' => $match->sku];
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
