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
     * @param  list<string>  $prioritySkus  van primero (vigentes), aunque sean de otra familia (§6.3)
     * @return array{items: list<array{sku: string, description: string, family: string, unit: string, price_version_id: string}>, truncated: bool}
     */
    public function assistantCatalog(string $date, ?string $family, int $limit, array $prioritySkus = []): array;

    /**
     * Ítems activos con precio vigente parecidos al texto (sin costos). Hasta $limit, por score descendente.
     *
     * @return list<array{id: string, sku: string, description: string, unit: string, family: string, price_version_id: string, price: string, valid_until: string, score: float}>
     */
    public function similarItems(string $q, ?string $family, int $limit): array;

    /** @return Collection<int, array<string, mixed>> */
    public function catalogWithHistory(): Collection;

    /**
     * @param  list<string>  $ids
     * @return array<string, string> SKU por id de ítem.
     */
    public function skusByIds(array $ids): array;

    public function skuExists(string $sku): bool;

    /**
     * Ítem activo de la familia cuya descripción normalizada coincide exactamente.
     *
     * @return array{id: string, sku: string}|null
     */
    public function activeExactMatch(string $description, string $family): ?array;

    /**
     * Advisory lock transaccional por familia (solo pgsql; no-op en otros motores) para serializar la creación
     * de ítems de líneas libres. Toma los bloqueos en orden alfabético.
     *
     * @param  list<string>  $families
     */
    public function lockFreeLineFamilies(array $families): void;

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
