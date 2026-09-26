<?php

namespace App\Application\Quotes;

use App\Domain\Audit;
use App\Domain\Quotes\FollowupPolicy;
use App\Models\User;
use App\Repositories\Contracts\QuoteEmissionRepository;
use App\Repositories\Contracts\QuoteFollowupRepository;
use App\Repositories\Contracts\QuoteRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Registra un evento de seguimiento comercial. Bloqueo: cotización (FOR UPDATE)
 * -> eventos (lectura dentro de la misma transacción).
 */
final class RecordQuoteFollowup
{
    public function __construct(
        private QuoteRepository $quotes,
        private QuoteEmissionRepository $emissions,
        private QuoteFollowupRepository $followups,
        private FollowupPolicy $policy,
    ) {}

    /**
     * @param  array{type: string, channel?: ?string, occurred_at: string, note?: ?string}  $data
     * @return array<string, mixed>
     */
    public function execute(string $id, User $actor, array $data): array
    {
        return DB::transaction(function () use ($id, $actor, $data) {
            $quote = $this->quotes->findVisible($id, $actor, true);
            abort_unless($quote, 404);
            $authorId = $quote->created_by === null ? null : (int) $quote->created_by;
            abort_unless($this->policy->mayRecord($actor->role, $actor->id, $authorId), 403, 'Solo un administrador o el cotizador dueño puede registrar seguimiento.');
            abort_unless($quote->status === 'issued', 409, 'Solo se registra seguimiento de cotizaciones emitidas.');

            $emission = $this->emissions->forQuote($id);
            abort_unless($emission !== null, 409, 'La cotización no tiene emisión.');

            $existing = $this->followups->forQuote($id)->pluck('type')->all();
            $conflict = $this->policy->conflict($data['type'], $existing);
            abort_if($conflict !== null, 409, (string) $conflict);

            $occurredAt = CarbonImmutable::parse($data['occurred_at']);
            $dateError = $this->policy->dateError($occurredAt, CarbonImmutable::parse($emission['issued_at'])->startOfSecond(), now());
            if ($dateError !== null) {
                throw ValidationException::withMessages(['occurred_at' => $dateError]);
            }

            $followupId = (string) Str::orderedUuid();
            $note = isset($data['note']) && trim($data['note']) !== '' ? trim($data['note']) : null;
            $this->followups->create([
                'id' => $followupId, 'quote_id' => $id, 'type' => $data['type'],
                'channel' => $data['type'] === 'sent' ? $data['channel'] : null,
                'occurred_at' => $occurredAt, 'note' => $note, 'created_by' => $actor->id,
            ]);
            Audit::record($actor->id, 'quote.followup_recorded', $id, [
                'followup_id' => $followupId, 'type' => $data['type'],
                'channel' => $data['type'] === 'sent' ? $data['channel'] : null,
                'occurred_at' => $occurredAt->toIso8601String(), 'note_length' => $note === null ? 0 : mb_strlen($note),
            ]);

            return $this->overview($id, $existing === [] ? [$data['type']] : [...$existing, $data['type']]);
        });
    }

    /**
     * @param  list<string>  $types
     * @return array<string, mixed>
     */
    private function overview(string $id, array $types): array
    {
        return [
            'commercial_status' => $this->policy->commercialStatus($types),
            'followups' => $this->followups->forQuote($id)->all(),
        ];
    }
}
