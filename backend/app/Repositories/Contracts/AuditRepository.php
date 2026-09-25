<?php

namespace App\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface AuditRepository
{
    public function record(?int $userId, string $action, string $subjectId, array $details = []): void;

    public function paginateWithUserNames(int $perPage = 30): LengthAwarePaginator;
}
