<?php

namespace App\Domain;

use App\Repositories\Contracts\AuditRepository;

final class Audit
{
    public static function record(?int $userId, string $action, string $subjectId, array $details = []): void
    {
        app(AuditRepository::class)->record($userId, $action, $subjectId, $details);
    }
}
