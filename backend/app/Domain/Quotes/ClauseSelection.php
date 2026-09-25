<?php

namespace App\Domain\Quotes;

use App\Repositories\Contracts\ClauseRepository;
use Illuminate\Validation\ValidationException;

/**
 * Selección de cláusulas al guardar una cotización (`CreateQuote`). Se
 * ejecuta dentro de la transacción de creación, con bloqueo compartido
 * cláusula → versión.
 */
final class ClauseSelection
{
    public function __construct(private ClauseRepository $clauses) {}

    /**
     * @param  array<string, string|null>  $clauseVersions  Tipo => id de versión de cláusula.
     * @param  array<string, mixed>  $texts  Campos de la instantánea (scope, exclusions, payment_terms, warranty, validity_terms, observations).
     * @return list<array<string, mixed>>
     */
    public function resolve(string $family, array $clauseVersions, array $texts, int $validityDays): array
    {
        $versionIds = array_values(array_filter(
            array_map(static fn ($id) => is_string($id) ? $id : null, $clauseVersions)
        ));
        $locked = $this->clauses->lockedVersions($versionIds);

        $result = [];
        foreach (ClauseType::cases() as $type) {
            $versionId = $clauseVersions[$type->value] ?? null;
            $fieldText = trim((string) ($texts[$type->snapshotField()] ?? ''));

            if ($versionId === null) {
                continue;
            }

            $row = $locked->get($versionId);
            if (
                ! $row
                || $row->status !== 'current'
                || ! (bool) $row->clause_active
                || $row->clause_type !== $type->value
                || $row->clause_family !== $family
            ) {
                throw ValidationException::withMessages([
                    "clause_versions.{$type->value}" => 'La cláusula seleccionada no está vigente, activa, o no corresponde a esta familia/tipo.',
                ]);
            }

            if (in_array($type, [ClauseType::Validity, ClauseType::Observations], true) && $fieldText === '') {
                throw ValidationException::withMessages([
                    $type->snapshotField() => 'El texto no puede estar vacío cuando se referencia una cláusula.',
                ]);
            }

            $modified = ClauseText::hash($fieldText) !== ClauseText::hash(ClauseText::render($row->body, $validityDays));

            $result[] = [
                'type' => $type->value,
                'clause_id' => $row->clause_id,
                'clause_version_id' => $row->id,
                'version' => (int) $row->version,
                'family' => $row->clause_family,
                'title' => $row->clause_title,
                'body_hash' => $row->body_hash,
                'modified' => $modified,
            ];
        }

        return $result;
    }
}
