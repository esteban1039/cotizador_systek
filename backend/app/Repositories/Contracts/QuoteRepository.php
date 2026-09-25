<?php

namespace App\Repositories\Contracts;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface QuoteRepository
{
    public function lockRevisionRoot(string $id): void;

    public function nextRevisionNumber(string $rootId): int;

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
