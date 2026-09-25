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

final class PublishClauseVersion
{
    public function __construct(private ClauseRepository $clauses) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>|null
     */
    public function execute(string $id, array $input, ?User $actor): ?array
    {
        return DB::transaction(function () use ($id, $input, $actor) {
            $locked = $this->clauses->lockWithCurrentVersion($id);
            if (! $locked) {
                return null;
            }
            $clause = $locked['clause'];
            $type = ClauseType::from($clause['type']);
            $body = $input['body'];

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

            $newHash = ClauseText::hash($body);
            if ($locked['current_version'] && $locked['current_version']['body_hash'] === $newHash) {
                throw ValidationException::withMessages(['body' => 'El texto no cambió.']);
            }

            $version = $this->clauses->appendVersion($id, [
                'id' => (string) Str::uuid(), 'body' => $body, 'body_hash' => $newHash,
                'origin' => 'admin', 'reason' => $input['reason'], 'published_by' => $actor?->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            Audit::record($actor?->id, 'clause.version_published', $id, ['version' => $version['version'], 'reason' => $input['reason']]);

            return $version;
        });
    }
}
