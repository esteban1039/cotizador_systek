<?php

namespace App\Repositories\Contracts;

interface KnowledgeRepository
{
    /** Id del administrador activo con ese correo, o null. */
    public function activeAdminId(string $email): ?int;

    /**
     * Crea o actualiza (idempotente) la entrada de una cotización raíz. Conserva `issued`, y el estado si un admin ya la revisó.
     *
     * @param  array<string, mixed>  $entry
     * @return 'created'|'updated'|'unchanged'
     */
    public function upsertFromQuote(array $entry): string;

    /**
     * Crea o actualiza (idempotente) la entrada de un grupo de Drive por `source_ref`.
     *
     * @param  array<string, mixed>  $entry
     * @return 'created'|'updated'|'unchanged'
     */
    public function importDriveGroup(array $entry): string;

    /**
     * Última revisión aprobada/emitida por cotización raíz.
     *
     * @return list<array{quote_id: string, root_id: string, revision: int, issued: bool, snapshot: array<string, mixed>}>
     */
    public function approvedSnapshots(): array;

    /**
     * @param  list<string>  $ids
     * @return array<string, array{sku: string, description: string}>
     */
    public function catalogByIds(array $ids): array;

    /**
     * Ítems creados al aprobar las líneas libres de la cotización, por `free_line_id`.
     *
     * @return array<string, array{sku: string, description: string}>
     */
    public function freeLineCatalog(string $quoteId): array;

    /**
     * Precedentes activos ordenados por relevancia (§6.2; respaldo LIKE en SQLite). Una por raíz, tope de ai_assisted y de Drive.
     * Incluye `reference_price_cents` dentro de `lines`: el llamador debe descartarlo antes de enviar nada.
     *
     * @return list<array{id: string, source: string, family: string, requirement_text: string, scope: ?string, exclusions: ?string, lines: list<array<string, mixed>>, ai_assisted: bool, captured_at: string, score: float}>
     */
    public function similar(string $text, ?string $family, int $limit, ?string $excludeRootId = null): array;

    /**
     * SKU más frecuentes en las entradas activas de la familia (todas si es null).
     *
     * @return list<string>
     */
    public function skuFrequencyForFamily(?string $family, int $limit): array;

    /**
     * Una fila por propuesta (§5.2). Sin texto libre, respuesta del modelo ni precios.
     *
     * @param  array<string, mixed>  $row
     */
    public function recordAssistRequest(array $row): void;

    /**
     * Cotización aprobada o emitida (instantánea incluida) para capturarla; null si no está en esos estados.
     *
     * @return array{quote_id: string, root_id: string, revision: int, issued: bool, snapshot: array<string, mixed>}|null
     */
    public function approvedQuote(string $id): ?array;

    /** Marca la entrada de la raíz como emitida (trust 1.10). Devuelve false si no existe entrada. */
    public function markIssued(string $rootId): bool;

    /** Enlaza una propuesta a la raíz solo si es del usuario, no está enlazada y tiene 24 h o menos. */
    public function linkAssistRequest(string $requestId, int $userId, string $rootId): bool;

    /**
     * Propuesta más reciente enlazada a la raíz.
     *
     * @return array{id: string, proposed_lines: list<array<string, mixed>>}|null
     */
    public function assistRequestForRoot(string $rootId): ?array;

    /**
     * Contadores de la comparación propuesta vs. aprobada.
     *
     * @param  array{kept: int, qty_changed: int, removed: int, added: int}  $counts
     */
    public function recordAssistOutcome(string $requestId, array $counts): void;

    /**
     * Página de entradas para la pantalla de administración (orden: más recientes primero).
     *
     * @param  array{status?: ?string, source?: ?string, family?: ?string, q?: ?string, page?: int}  $filters
     * @return array{data: list<array<string, mixed>>, meta: array{page: int, per_page: int, total: int, last_page: int}}
     */
    public function paginate(array $filters): array;

    /**
     * Detalle de una entrada con sus líneas; `reference_price_cents` como cadena decimal (solo administración).
     *
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array;

    /**
     * Bloquea la entrada para revisarla y devuelve su estado y textos actuales.
     *
     * @return array{status: string, requirement_text: string, scope: ?string, exclusions: ?string, scrub_flags: list<string>}|null
     */
    public function lockForReview(string $id): ?array;

    /** Cambia el estado y deja constancia de quién y por qué. */
    public function setStatus(string $id, string $status, int $userId, string $reason): void;

    /**
     * Reemplaza textos ya depurados y banderas; registra revisor y motivo.
     *
     * @param  array{requirement_text?: string, scope?: ?string, exclusions?: ?string}  $texts
     * @param  list<string>  $scrubFlags
     */
    public function applyEdit(string $id, array $texts, array $scrubFlags, int $userId, string $reason): void;

    /**
     * Métricas de §9.3. Sin textos, clientes ni costos.
     *
     * @return array<string, mixed>
     */
    public function metrics(): array;
}
