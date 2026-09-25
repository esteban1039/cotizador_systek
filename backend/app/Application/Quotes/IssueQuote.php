<?php

namespace App\Application\Quotes;

use App\Domain\Audit;
use App\Domain\CompanyProfile;
use App\Domain\Quotes\ApprovalValidation;
use App\Domain\Quotes\EmissionPolicy;
use App\Domain\Quotes\SnapshotCompatibility;
use App\Models\User;
use App\Repositories\Contracts\CompanyRepository;
use App\Repositories\Contracts\QuoteEmissionRepository;
use App\Repositories\Contracts\QuoteRepository;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Emisión oficial (docs/diseno-emision-oficial.md). Orden de bloqueos:
 * cotización raíz -> cotización -> cliente -> ítem -> precio -> regla ->
 * cláusula -> versión de cláusula -> empresa (compartido).
 */
final class IssueQuote
{
    public function __construct(
        private QuoteRepository $quotes,
        private QuoteEmissionRepository $emissions,
        private CompanyRepository $companies,
        private ApprovalValidation $validation,
        private EmissionPolicy $policy,
        private QuotePdfRenderer $renderer,
    ) {}

    /**
     * @return array<string, mixed> Emisión creada (sin datos bancarios).
     */
    public function execute(string $id, User $actor, string $reason): array
    {
        return DB::transaction(function () use ($id, $actor, $reason) {
            $visible = $this->quotes->findVisible($id, $actor);
            abort_unless($visible, 404);
            $rootId = $visible->root_quote_id ?? $visible->id;
            $this->quotes->lockRevisionRoot($rootId);
            $record = $this->quotes->findVisible($id, $actor, true);
            abort_unless($record, 404);

            abort_if($record->status === 'issued', 409, 'La cotización ya fue emitida.');
            abort_unless($record->status === 'approved', 409, 'Solo se pueden emitir cotizaciones aprobadas internamente.');
            abort_if((int) $record->revision_number < $this->quotes->latestRevisionNumber($rootId), 409, 'Existe una revisión posterior; solo se emite la más reciente.');
            $this->fail($record->quote_number === null, 'quote', 'La cotización no tiene número asignado.');

            $rawSnapshot = $record->snapshot;
            $snapshot = json_decode($rawSnapshot, true, flags: JSON_THROW_ON_ERROR);
            $this->fail(($snapshot['valid_until'] ?? '') < now('America/Bogota')->toDateString(), 'quote', 'La vigencia terminó. Crea una nueva revisión.');
            $snapshot = SnapshotCompatibility::normalize($snapshot, $record);
            $this->validation->check($snapshot, $record, true);

            $company = $this->companies->lockedCurrentForEmission();
            $missing = CompanyProfile::missing($company === null ? null : array_merge(
                Arr::only($company, ['legal_name', 'nit', 'address', 'phone', 'email', 'signer_name', 'signer_title']),
                ['bank_account' => $company['bank_account'] !== null]
            ));
            $this->fail($company === null || $missing !== [], 'company', 'La empresa emisora está incompleta: faltan '.implode(', ', $missing).'.');

            $requires = (bool) $company['emission_requires_authorization'];
            $authorId = $record->created_by === null ? null : (int) $record->created_by;
            abort_unless($this->policy->mayIssue($actor->role, $actor->id, $authorId, $requires), 403, $this->policy->denialMessage($requires));
            $approval = $this->quotes->approvingReview($id);
            $this->fail(! $this->policy->approvalIsIndependent($approval === null ? null : (int) $approval->user_id, $authorId), 'quote', 'La aprobación debe ser de una persona distinta del autor.');

            $emissionId = (string) Str::uuid();
            $issuedAt = now();
            $quote = $this->renderer->quoteData($record, $snapshot);
            $issuer = Arr::only($company, ['legal_name', 'trade_name', 'nit', 'address', 'phone', 'email', 'website', 'signer_name', 'signer_title']);
            $quote['issuer'] = $issuer;
            $bytes = $this->renderer->official($quote, $company['bank_account'], $emissionId, $issuedAt->toIso8601String());
            $digits = preg_replace('/\D/', '', (string) $company['bank_account']['account_number']) ?? '';
            $filename = $quote['quote_number'].'-'.$quote['version_label'].'.pdf';

            $previous = $this->emissions->activeForRoot($rootId);
            $this->emissions->create([
                'id' => $emissionId, 'quote_id' => $id, 'root_quote_id' => $rootId,
                'quote_number' => $quote['quote_number'], 'revision_number' => (int) $record->revision_number,
                'approval_review_id' => $approval->id, 'company_version_id' => $company['id'],
                'emission_requires_authorization' => $requires,
                'issuer' => $issuer,
                'bank_summary' => [
                    'bank_name' => $company['bank_account']['bank_name'] ?? null,
                    'account_type' => $company['bank_account']['account_type'] ?? null,
                    'last4' => substr($digits, -4),
                ],
                'clause_version_ids' => array_values(array_column($snapshot['clauses'] ?? [], 'clause_version_id')),
                'snapshot_sha256' => hash('sha256', $rawSnapshot), 'pdf_sha256' => hash('sha256', $bytes), 'pdf_size' => strlen($bytes),
                'filename' => $filename, 'template_version' => QuotePdfRenderer::OFFICIAL_TEMPLATE_VERSION,
                'renderer_version' => $this->renderer->rendererVersion(),
                'reason' => $reason, 'issued_by' => $actor->id, 'issued_at' => $issuedAt,
            ], $bytes);
            $this->quotes->markIssued($id);

            foreach ($previous as $old) {
                $this->emissions->markSuperseded($old['id'], $emissionId);
                Audit::record($actor->id, 'quote.emission_superseded', $old['quote_id'], ['emission_id' => $old['id'], 'superseded_by' => $emissionId]);
            }
            $emission = $this->emissions->forQuote($id);
            Audit::record($actor->id, 'quote.issued', $id, [
                'emission_id' => $emissionId, 'quote_number' => $quote['quote_number'], 'revision_number' => (int) $record->revision_number,
                'pdf_sha256' => $emission['pdf_sha256'], 'snapshot_sha256' => $emission['snapshot_sha256'],
                'company_version' => $emission['company_version'], 'emission_requires_authorization' => $requires, 'reason' => $reason,
            ]);

            return $emission;
        });
    }

    private function fail(bool $condition, string $field, string $message): void
    {
        if ($condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
