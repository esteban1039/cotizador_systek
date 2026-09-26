<?php

namespace App\Repositories\Contracts;

use Illuminate\Support\Collection;

interface CatalogRepository
{
    /** @return Collection<int, array<string, mixed>> */
    public function currentPrices(string $date): Collection;

    /**
     * Catálogo vigente reducido para el asistente: sin precios, costos ni vigencias.
     * Devuelve hasta $limit filas y `truncated` si había más.
     *
     * @return array{items: list<array{sku: string, description: string, family: string, unit: string, price_version_id: string}>, truncated: bool}
     */
    public function assistantCatalog(string $date, ?string $family, int $limit): array;

    /** @return Collection<int, array<string, mixed>> */
    public function catalogWithHistory(): Collection;

    public function skuExists(string $sku): bool;

    /** @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public function createItem(array $attributes): array;

    /**
     * Lock the item, archive its approved prices, and insert the next version.
     * Must be called inside a transaction that also records the audit event.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>|null Null if the item does not exist.
     */
    public function publishPrice(string $itemId, array $attributes): ?array;

    /** Must be called inside a transaction; returns false if the item is missing. */
    public function setActive(string $itemId, bool $active): bool;

    /** @return Collection<int, array<string, mixed>> */
    public function commercialRules(): Collection;

    /** @param array<string, mixed> $values */
    public function saveCommercialRule(string $family, array $values): void;
}
