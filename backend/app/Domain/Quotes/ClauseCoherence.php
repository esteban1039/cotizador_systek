<?php

namespace App\Domain\Quotes;

use App\Repositories\Contracts\ClauseRepository;
use Illuminate\Validation\ValidationException;

/**
 * Coherencia de cláusulas al enviar a revisión y al aprobar (plan §2.3,
 * diseño §4.4). E1-E4 siempre bloquean; M1 advierte al enviar y bloquea al
 * aprobar; F1/F2 solo advierten.
 */
final class ClauseCoherence
{
    /** Tipos cuyo texto se compara contra otras familias (E4); vigencia se excluye. */
    private const HASH_CHECKED_TYPES = [
        ClauseType::ScopeBase, ClauseType::Exclusions, ClauseType::Payment, ClauseType::Warranty, ClauseType::Observations,
    ];

    public function __construct(private ClauseRepository $clauses) {}

    /**
     * @param  array<string, mixed>  $snapshot  Instantánea ya normalizada (`SnapshotCompatibility`).
     * @param  list<array<string, mixed>>  $calculatedLines
     * @return array{flags: list<string>, clauses: list<array<string, mixed>>}
     */
    public function check(array $snapshot, array $calculatedLines, bool $strict): array
    {
        $family = $snapshot['family'] ?? null;
        if ($family === null) {
            throw ValidationException::withMessages([
                'clauses' => 'Esta cotización es anterior a las cláusulas por familia. Crea una nueva revisión.',
            ]);
        }

        $clauses = $snapshot['clauses'] ?? [];
        $flags = [];

        $this->checkReferencedClauses($clauses, $family);

        $referencedTypes = array_column($clauses, 'type');
        foreach ([ClauseType::Payment, ClauseType::Warranty, ClauseType::Validity] as $required) {
            if (! in_array($required->value, $referencedTypes, true)) {
                $message = "Falta la cláusula de {$this->label($required)}. La aprobación quedará bloqueada hasta incluirla.";
                if ($strict) {
                    throw ValidationException::withMessages(['clauses' => $message]);
                }
                $flags[] = $message;
            }
        }

        foreach ($clauses as $clause) {
            if ($clause['modified'] ?? false) {
                $flags[] = "La cláusula «{$clause['title']}» se modificó respecto al texto publicado.";
            }
        }

        foreach ($calculatedLines as $index => $line) {
            if (($line['family'] ?? null) !== null && $line['family'] !== $family) {
                $flags[] = 'Partida '.($index + 1).': pertenece a una familia distinta a la de la cotización.';
            }
        }

        $flags = array_merge($flags, $this->checkTextHashes($snapshot, $family));

        return ['flags' => array_values(array_unique($flags)), 'clauses' => $clauses];
    }

    /** @param list<array<string, mixed>> $clauses */
    private function checkReferencedClauses(array $clauses, string $family): void
    {
        if ($clauses === []) {
            return;
        }
        $versionIds = array_column($clauses, 'clause_version_id');
        $current = $this->clauses->lockedVersions($versionIds);

        foreach ($clauses as $clause) {
            $row = $current->get($clause['clause_version_id']);
            if (! $row || $row->status !== 'current' || ! (bool) $row->clause_active) {
                throw ValidationException::withMessages([
                    'clauses' => "La cláusula «{$clause['title']}» tiene una versión nueva o fue desactivada. Crea una nueva revisión.",
                ]);
            }
            if ($row->clause_family !== $family) {
                throw ValidationException::withMessages([
                    'clauses' => "La cláusula «{$clause['title']}» corresponde a la familia {$row->clause_family} y la cotización es de {$family}.",
                ]);
            }
        }
    }

    /** @return list<string> */
    private function checkTextHashes(array $snapshot, string $family): array
    {
        $hashes = [];
        foreach (self::HASH_CHECKED_TYPES as $type) {
            $text = trim((string) ($snapshot[$type->snapshotField()] ?? ''));
            if ($text === '') {
                continue;
            }
            $hashes[$type->value] = ClauseText::hash($text);
        }
        if ($hashes === []) {
            return [];
        }
        $matches = $this->clauses->hashMatches(array_values($hashes));
        if ($matches->isEmpty()) {
            return [];
        }
        $byHash = $matches->groupBy('body_hash');

        foreach ($hashes as $typeValue => $hash) {
            $rows = $byHash->get($hash);
            if (! $rows) {
                continue;
            }
            $ownFamilyMatch = $rows->contains(fn ($row) => $row->family === $family);
            $otherFamilyMatch = $rows->first(fn ($row) => $row->family !== $family);
            if ($otherFamilyMatch && ! $ownFamilyMatch) {
                throw ValidationException::withMessages([
                    'clauses' => "El texto de {$typeValue} corresponde a una cláusula de la familia {$otherFamilyMatch->family} y la cotización es de {$family}.",
                ]);
            }
        }

        return [];
    }

    private function label(ClauseType $type): string
    {
        return match ($type) {
            ClauseType::Payment => 'forma de pago',
            ClauseType::Warranty => 'garantía',
            ClauseType::Validity => 'vigencia',
            default => $type->value,
        };
    }
}
