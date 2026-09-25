<?php

namespace App\Repositories\Eloquent;

use App\Repositories\Contracts\AuditRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class EloquentAuditRepository implements AuditRepository
{
    public function record(?int $userId, string $action, string $subjectId, array $details = []): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => $userId, 'action' => $action, 'subject_id' => $subjectId,
            'details' => json_encode($details, JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }

    public function paginateWithUserNames(int $perPage = 30): LengthAwarePaginator
    {
        return DB::table('audit_logs')->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')
            ->select('audit_logs.*', 'users.name as user_name')->orderByDesc('audit_logs.id')->paginate($perPage);
    }
}
