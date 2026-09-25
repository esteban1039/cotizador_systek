<?php

namespace App\Application\Quotes;

use App\Domain\Audit;
use App\Domain\Quotes\ClauseText;
use App\Domain\Quotes\ClauseType;
use App\Models\User;
use App\Repositories\Contracts\ClauseRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CreateClause
{
    public function __construct(private ClauseRepository $clauses) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function execute(array $input, ?User $actor, string $origin = 'admin'): array
    {
        $type = ClauseType::from($input['type']);
        $body = $input['body'];
        $this->assertValidBody($type, $body);

        return DB::transaction(function () use ($input, $actor, $origin, $type, $body) {
            $clauseId = (string) Str::uuid();
            $clause = $this->clauses->create([
                'id' => $clauseId,
                'family' => $input['family'],
                'type' => $type->value,
                'title' => $input['title'],
                'is_default' => (bool) ($input['is_default'] ?? false),
                'active' => true,
                'is_demo' => false,
                'created_by' => $actor?->id,
                'created_at' => now(), 'updated_at' => now(),
            ], [
                'id' => (string) Str::uuid(),
                'clause_id' => $clauseId,
                'version' => 1,
                'status' => 'current',
                'body' => $body,
                'body_hash' => ClauseText::hash($body),
                'origin' => $origin,
                'reason' => $input['reason'],
                'published_by' => $actor?->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            $note = $origin === 'initial_draft' ? 'Redacción inicial propuesta' : $input['reason'];
            Audit::record($actor?->id, 'clause.created', $clauseId, [
                'origin' => $origin, 'note' => $note, 'family' => $input['family'], 'type' => $type->value, 'version' => 1,
            ]);

            return $clause;
        });
    }

    private function assertValidBody(ClauseType $type, string $body): void
    {
        $errors = ClauseText::validate($body);
        if ($type !== ClauseType::Validity && str_contains($body, '{vigencia_dias}')) {
            $errors[] = 'El marcador {vigencia_dias} solo se admite en cláusulas de vigencia.';
        }
        if (mb_strlen($body) > $type->maxLength()) {
            $errors[] = 'El texto supera la longitud máxima permitida ('.$type->maxLength().' caracteres).';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages(['body' => $errors]);
        }
    }
}
