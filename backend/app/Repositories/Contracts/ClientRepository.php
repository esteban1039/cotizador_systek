<?php

namespace App\Repositories\Contracts;

use Illuminate\Support\Collection;

interface ClientRepository
{
    /** @return Collection<int, array<string, mixed>> */
    public function directory(): Collection;

    public function exists(string $id): bool;

    public function nitExists(string $nit): bool;

    /** @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public function createClient(array $attributes): array;

    /** @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public function createSite(string $clientId, array $attributes): array;

    /** @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public function createContact(string $clientId, array $attributes): array;

    /** Bloqueo compartido. Null si el cliente no existe. */
    public function lockedTaxProfile(string $clientId): ?bool;

    /**
     * Bloqueo exclusivo (FOR UPDATE). Null si el cliente no existe.
     *
     * @return array{previous: bool, withholds_vat: bool}|null
     */
    public function updateTaxProfile(string $clientId, bool $withholds): ?array;
}
