<?php

namespace App\Repositories\Contracts;

use Illuminate\Support\Collection;

interface QuoteEmissionRepository
{
    /**
     * Inserta la emisión y su archivo (PDF cifrado). Dentro de transacción.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, string $pdfBytes): void;

    /**
     * Emisión de una cotización (sin el archivo), o null.
     *
     * @return array<string, mixed>|null
     */
    public function forQuote(string $quoteId): ?array;

    /**
     * Emisiones vigentes (no reemplazadas) del linaje.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function activeForRoot(string $rootId): Collection;

    public function markSuperseded(string $emissionId, string $byEmissionId): void;

    /**
     * Bytes del PDF descifrados; null si falta o no se puede descifrar.
     */
    public function fileContent(string $emissionId): ?string;
}
