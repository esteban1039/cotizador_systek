<?php

namespace App\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface HistoryRepository
{
    public function paginate(?string $search, ?string $status): LengthAwarePaginator;

    public function find(string $id, bool $lock = false): ?array;

    public function findSource(string $sourceId): ?array;

    public function registerSource(array $attributes): void;

    public function sources(string $documentId): array;

    public function findHash(string $hash): ?array;

    public function create(array $attributes): array;

    public function review(string $id, array $attributes): array;

    public function candidates(?string $nit, ?string $name): array;
}
