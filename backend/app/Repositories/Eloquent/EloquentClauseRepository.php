<?php

namespace App\Repositories\Eloquent;

use App\Domain\Quotes\ClauseType;
use App\Models\Clause;
use App\Models\ClauseVersion;
use App\Repositories\Contracts\ClauseRepository;
use Illuminate\Support\Collection;

final class EloquentClauseRepository implements ClauseRepository
{
    public function adminList(?string $family, ?string $type, bool $includeInactive): Collection
    {
        return Clause::query()
            ->when($family, fn ($query) => $query->where('family', $family))
            ->when($type, fn ($query) => $query->where('type', $type))
            ->when(! $includeInactive, fn ($query) => $query->where('active', true))
            ->with(['versions' => fn ($query) => $query->where('status', 'current')->with('publisher')])
            ->get()
            ->sortBy(function (Clause $clause): array {
                return [array_search($clause->type, ClauseType::values()), $clause->is_default ? 0 : 1, $clause->title];
            })
            ->values()
            ->map(fn (Clause $clause) => $this->toListItem($clause));
    }

    public function adminDetail(string $id): ?array
    {
        $clause = Clause::query()->with(['versions.publisher'])->find($id);
        if (! $clause) {
            return null;
        }

        return array_merge($this->toListItem($clause), [
            'versions' => $clause->versions->map(fn (ClauseVersion $version): array => [
                'id' => $version->id,
                'version' => $version->version,
                'status' => $version->status,
                'body' => $version->body,
                'origin' => $version->origin,
                'reason' => $version->reason,
                'published_by_name' => $version->publisher?->name,
                'created_at' => $version->created_at,
            ])->all(),
        ]);
    }

    public function titleExists(string $family, string $type, string $title): bool
    {
        return Clause::query()->where('family', $family)->where('type', $type)->where('title', $title)->exists();
    }

    public function create(array $clause, array $firstVersion): array
    {
        if ($clause['is_default'] ?? false) {
            $this->lockDefaultGroup($clause['family'], $clause['type']);
            Clause::query()->where('family', $clause['family'])->where('type', $clause['type'])->update(['is_default' => false]);
        }
        $model = Clause::query()->create($clause);
        ClauseVersion::query()->create($firstVersion);

        return $this->toListItem($model->fresh('versions.publisher'));
    }

    public function lockWithCurrentVersion(string $id): ?array
    {
        $clause = Clause::query()->whereKey($id)->lockForUpdate()->first();
        if (! $clause) {
            return null;
        }
        $current = ClauseVersion::query()->where('clause_id', $id)->where('status', 'current')->lockForUpdate()->first();

        return [
            'clause' => $clause->toArray(),
            'current_version' => $current?->toArray(),
        ];
    }

    public function appendVersion(string $id, array $attributes): array
    {
        $current = ClauseVersion::query()->where('clause_id', $id)->where('status', 'current')->lockForUpdate()->first();
        $nextVersion = $current ? $current->version + 1 : 1;
        if ($current) {
            $current->update(['status' => 'historical']);
        }
        $version = ClauseVersion::query()->create(array_merge($attributes, [
            'clause_id' => $id, 'version' => $nextVersion, 'status' => 'current',
        ]));

        return [
            'id' => $version->id, 'clause_id' => $id, 'version' => $version->version, 'status' => 'current',
            'body' => $version->body, 'origin' => $version->origin, 'created_at' => $version->created_at,
        ];
    }

    public function update(string $id, array $changes): ?array
    {
        $clause = Clause::query()->whereKey($id)->lockForUpdate()->first();
        if (! $clause) {
            return null;
        }
        if (($changes['active'] ?? null) === false) {
            $changes['is_default'] = false;
        }
        if (($changes['is_default'] ?? null) === true) {
            $this->lockDefaultGroup($clause->family, $clause->type);
            Clause::query()->where('family', $clause->family)->where('type', $clause->type)->where('id', '!=', $id)->update(['is_default' => false]);
        }
        $clause->update($changes);

        return $this->toListItem($clause->fresh('versions.publisher'));
    }

    public function currentForFamily(string $family): Collection
    {
        $items = Clause::query()->where('family', $family)->where('active', true)
            ->with(['versions' => fn ($query) => $query->where('status', 'current')])
            ->get()
            ->filter(fn (Clause $clause) => $clause->versions->isNotEmpty())
            ->map(function (Clause $clause) {
                $version = $clause->versions->first();

                return (object) [
                    'clause_id' => $clause->id, 'clause_version_id' => $version->id, 'family' => $clause->family,
                    'type' => $clause->type, 'title' => $clause->title, 'is_default' => (bool) $clause->is_default,
                    'version' => $version->version, 'origin' => $version->origin, 'body' => $version->body,
                ];
            })
            ->values()
            ->all();

        usort($items, function ($a, $b) {
            $order = array_search($a->type, ClauseType::values()) <=> array_search($b->type, ClauseType::values());
            if ($order !== 0) {
                return $order;
            }
            $order = ($b->is_default <=> $a->is_default);
            if ($order !== 0) {
                return $order;
            }

            return $a->title <=> $b->title;
        });

        return collect($items);
    }

    public function lockedVersions(array $versionIds): Collection
    {
        if ($versionIds === []) {
            return collect();
        }

        // Bloqueo compartido en el orden del diseño (cláusula → versión de
        // cláusula), en dos consultas ordenadas por id: evita el
        // interbloqueo 40P01 con `PublishClauseVersion::execute()`
        // (`lockWithCurrentVersion` también bloquea cláusula antes que
        // versión). Un solo JOIN con `lockForUpdate()` bloqueaba ambas
        // tablas a la vez sin un orden garantizado.
        $clauseIds = ClauseVersion::query()->whereIn('id', $versionIds)
            ->orderBy('clause_id')->pluck('clause_id')->unique()->sort()->values()->all();
        if ($clauseIds !== []) {
            Clause::query()->whereIn('id', $clauseIds)->orderBy('id')->sharedLock()->get(['id']);
        }

        return ClauseVersion::query()->whereIn('clause_versions.id', $versionIds)
            ->join('clauses', 'clauses.id', '=', 'clause_versions.clause_id')
            ->orderBy('clause_versions.id')
            ->sharedLock()
            ->get([
                'clause_versions.id', 'clause_versions.clause_id', 'clause_versions.version', 'clause_versions.status',
                'clause_versions.body', 'clause_versions.body_hash',
                'clauses.family as clause_family', 'clauses.type as clause_type', 'clauses.active as clause_active', 'clauses.title as clause_title',
            ])
            ->keyBy('id');
    }

    public function hashMatches(array $hashes): Collection
    {
        if ($hashes === []) {
            return collect();
        }

        return ClauseVersion::query()->whereIn('clause_versions.body_hash', $hashes)
            ->join('clauses', 'clauses.id', '=', 'clause_versions.clause_id')
            ->get(['clause_versions.body_hash', 'clauses.family', 'clauses.type', 'clauses.title'])
            ->toBase();
    }

    /** @return array<string, mixed> */
    private function toListItem(Clause $clause): array
    {
        $current = $clause->versions->firstWhere('status', 'current');

        return [
            'id' => $clause->id, 'family' => $clause->family, 'type' => $clause->type, 'title' => $clause->title,
            'is_default' => (bool) $clause->is_default, 'active' => (bool) $clause->active, 'is_demo' => (bool) $clause->is_demo,
            'versions_count' => $clause->versions()->count(),
            'updated_at' => $clause->updated_at,
            'current_version' => $current ? [
                'id' => $current->id, 'version' => $current->version, 'body' => $current->body,
                'origin' => $current->origin, 'created_at' => $current->created_at,
                'published_by_name' => $current->publisher?->name,
            ] : null,
        ];
    }

    private function lockDefaultGroup(string $family, string $type): void
    {
        Clause::query()->where('family', $family)->where('type', $type)->orderBy('id')->lockForUpdate()->get();
    }
}
