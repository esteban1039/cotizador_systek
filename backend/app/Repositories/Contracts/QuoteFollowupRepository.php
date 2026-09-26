<?php

namespace App\Repositories\Contracts;

use Illuminate\Support\Collection;

interface QuoteFollowupRepository
{
    /**
     * Inserta un evento (append-only). Dentro de transacción.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): void;

    /**
     * Eventos de la cotización en orden de registro.
     *
     * @return Collection<int, array{id: string, type: string, channel: ?string, occurred_at: string, note: ?string, created_by: ?string, created_at: string}>
     */
    public function forQuote(string $quoteId): Collection;
}
