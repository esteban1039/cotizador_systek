<?php

namespace App\Repositories\Contracts;

use Illuminate\Support\Collection;

interface ClauseRepository
{
    /** @return Collection<int, array<string, mixed>> */
    public function adminList(?string $family, ?string $type, bool $includeInactive): Collection;

    /** @return array<string, mixed>|null */
    public function adminDetail(string $id): ?array;

    public function titleExists(string $family, string $type, string $title): bool;

    /**
     * Dentro de transacción. Si `is_default`, bloquea el grupo (family, type)
     * y limpia otras predeterminadas.
     *
     * @param  array<string, mixed>  $clause
     * @param  array<string, mixed>  $firstVersion
     * @return array<string, mixed>
     */
    public function create(array $clause, array $firstVersion): array;

    /**
     * Dentro de transacción: bloquea la cláusula y devuelve la cláusula con
     * su versión vigente (arreglos internos, no la forma pública de la API).
     *
     * @return array{clause: array<string, mixed>, current_version: array<string, mixed>|null}|null
     */
    public function lockWithCurrentVersion(string $id): ?array;

    /**
     * Tras `lockWithCurrentVersion`: marca histórica la vigente e inserta
     * version + 1.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function appendVersion(string $id, array $attributes): array;

    /**
     * Dentro de transacción: cambia active/is_default respetando las reglas
     * de predeterminada.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>|null
     */
    public function update(string $id, array $changes): ?array;

    /** Cláusulas activas de la familia con su versión vigente, para el editor. */
    public function currentForFamily(string $family): Collection;

    /** Bloqueo compartido cláusula → versión. Claves: id de versión. */
    public function lockedVersions(array $versionIds): Collection;

    /** Filas {body_hash, family, type, title} de versiones (cualquier estado) cuyo hash está en la lista. */
    public function hashMatches(array $hashes): Collection;
}
