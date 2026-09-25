<?php

namespace App\Repositories\Contracts;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface QuoteRepository
{
    public function lockRevisionRoot(string $id): void;

    public function nextRevisionNumber(string $rootId): int;

    /** Mayor número de revisión del linaje (raíz incluida). */
    public function latestRevisionNumber(string $rootId): int;

    /**
     * Última decisión `approve` de la cotización (id y user_id), o null.
     */
    public function approvingReview(string $id): ?object;

    /** Pasa a `issued`; solo `IssueQuote`, bajo bloqueo. */
    public function markIssued(string $id): void;

    public function rootNumber(string $rootId): ?string;

    public function visibleRevisions(string $rootId, User $user): Collection;

    public function create(array $record): void;

    public function visiblePage(User $user): LengthAwarePaginator;

    public function findVisible(string $id, User $user, bool $lock = false): ?object;

    public function findForReview(string $id): ?object;

    public function clientName(string $id): ?string;

    public function siteName(string $id): ?string;

    public function reviews(string $id): Collection;

    public function transition(string $id, string $status, array $review): void;
}
