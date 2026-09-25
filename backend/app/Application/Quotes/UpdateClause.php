<?php

namespace App\Application\Quotes;

use App\Domain\Audit;
use App\Models\User;
use App\Repositories\Contracts\ClauseRepository;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpdateClause
{
    public function __construct(private ClauseRepository $clauses) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>|null
     */
    public function execute(string $id, array $input, ?User $actor): ?array
    {
        return DB::transaction(function () use ($id, $input, $actor) {
            $changes = Arr::only($input, ['is_default', 'active']);
            if ($changes === []) {
                throw ValidationException::withMessages(['active' => 'Debes indicar al menos un cambio (activa o predeterminada).']);
            }

            if (($changes['is_default'] ?? false) === true && ($changes['active'] ?? null) !== true) {
                $locked = $this->clauses->lockWithCurrentVersion($id);
                if ($locked && ! $locked['clause']['active']) {
                    throw ValidationException::withMessages(['is_default' => 'No puedes marcar como predeterminada una cláusula inactiva.']);
                }
            }

            $clause = $this->clauses->update($id, $changes);
            if (! $clause) {
                return null;
            }
            Audit::record($actor?->id, 'clause.updated', $id, array_merge($changes, ['reason' => $input['reason']]));

            return $clause;
        });
    }
}
