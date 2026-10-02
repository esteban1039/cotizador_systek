<?php

namespace App\Application\Quotes;

use App\Domain\Audit;
use App\Domain\Quotes\KnowledgeScrubber;
use App\Repositories\Contracts\KnowledgeRepository;
use Illuminate\Support\Facades\DB;

/**
 * Revisión administrativa de una entrada (§9.3): excluir/activar y editar textos, que se vuelven a depurar.
 * La auditoría guarda solo ids, estados, nombres de campo y motivo; nunca los textos.
 */
final class ReviewKnowledgeEntry
{
    private const EDITABLE = ['requirement_text', 'scope', 'exclusions'];

    public function __construct(private KnowledgeRepository $knowledge, private KnowledgeScrubber $scrubber) {}

    /**
     * @param  array{status?: string, requirement_text?: string, scope?: ?string, exclusions?: ?string, reason: string}  $input
     * @return array<string, mixed>|null Entrada actualizada, o null si no existe.
     */
    public function execute(string $id, int $adminId, array $input): ?array
    {
        $found = DB::transaction(function () use ($id, $adminId, $input): bool {
            $current = $this->knowledge->lockForReview($id);
            if ($current === null) {
                return false;
            }
            $reason = trim($input['reason']);
            $edits = [];
            $flags = $current['scrub_flags'];
            foreach (self::EDITABLE as $field) {
                if (! array_key_exists($field, $input) || $input[$field] === $current[$field]) {
                    continue;
                }
                $clean = $input[$field] === null ? null : $this->scrubber->scrub((string) $input[$field]);
                $edits[$field] = $clean === null ? null : $clean['text'];
                $flags = array_values(array_unique(array_merge($flags, $clean['flags'] ?? [])));
            }
            if ($edits !== []) {
                $this->knowledge->applyEdit($id, $edits, $flags, $adminId, $reason);
                Audit::record($adminId, 'knowledge.edited', $id, ['fields' => array_keys($edits), 'reason' => $reason]);
            }
            if (isset($input['status']) && $input['status'] !== $current['status']) {
                $this->knowledge->setStatus($id, $input['status'], $adminId, $reason);
                Audit::record($adminId, 'knowledge.status_changed', $id, ['from' => $current['status'], 'to' => $input['status'], 'reason' => $reason]);
            }

            return true;
        });

        return $found ? $this->knowledge->find($id) : null;
    }
}
