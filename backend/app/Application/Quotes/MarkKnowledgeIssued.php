<?php

namespace App\Application\Quotes;

use App\Repositories\Contracts\KnowledgeRepository;
use Illuminate\Support\Facades\DB;

/** Al emitir, la entrada de la raíz pasa a `issued` (trust 1.10). Se invoca después del commit de la emisión. */
final class MarkKnowledgeIssued
{
    public function __construct(private KnowledgeRepository $knowledge) {}

    public function execute(string $rootId): bool
    {
        return DB::transaction(fn (): bool => $this->knowledge->markIssued($rootId));
    }
}
