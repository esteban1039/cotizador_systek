<?php

namespace App\Application\Quotes;

use App\Domain\Audit;
use App\Domain\Quotes\ClauseSelection;
use App\Domain\Quotes\QuotePricer;
use App\Domain\Quotes\VatWithholdingPolicy;
use App\Models\User;
use App\Repositories\Contracts\CatalogRepository;
use App\Repositories\Contracts\ClientRepository;
use App\Repositories\Contracts\KnowledgeRepository;
use App\Repositories\Contracts\QuoteNumberRepository;
use App\Repositories\Contracts\QuoteRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CreateQuote
{
    public function __construct(
        private QuoteRepository $quotes,
        private QuotePricer $pricer,
        private ClientRepository $clients,
        private QuoteNumberRepository $numbers,
        private ClauseSelection $clauseSelection,
        private KnowledgeRepository $knowledge,
        private CatalogRepository $catalog,
    ) {}

    public function execute(array $input, User $actor, ?string $sourceId = null): array
    {
        // Solo enlaza la propuesta de la IA; nunca entra en la instantánea.
        $assistRequestId = $input['assist_request_id'] ?? null;
        unset($input['assist_request_id']);

        return DB::transaction(function () use ($input, $actor, $sourceId, $assistRequestId) {
            $rootId = null;
            $number = 1;
            $quoteNumber = null;
            if ($sourceId !== null) {
                $source = $this->quotes->findVisible($sourceId, $actor);
                abort_unless($source, 404);
                $rootId = $source->root_quote_id ?? $source->id;
                $this->quotes->lockRevisionRoot($rootId);
                $number = $this->quotes->nextRevisionNumber($rootId);
                $quoteNumber = $this->quotes->rootNumber($rootId);
            }

            $withholds = (bool) $this->clients->lockedTaxProfile($input['client_id']);
            $rate = VatWithholdingPolicy::rateFor($withholds);
            $input['lines'] = $this->prepareFreeLines($input['lines'], $input['family']);
            $calculation = $this->pricer->calculate($input['lines'], $rate, $input['family']);

            $clauses = $this->clauseSelection->resolve(
                $input['family'],
                $input['clause_versions'] ?? [],
                $input,
                (int) $input['validity_days']
            );

            $id = (string) Str::uuid();
            if ($rootId === null) {
                $year = (int) now('America/Bogota')->year;
                $sequence = $this->numbers->allocate($year);
                $quoteNumber = sprintf('COT-%04d-%04d', $year, $sequence);
            }

            $metadata = ['root_quote_id' => $rootId, 'previous_quote_id' => $sourceId, 'revision_number' => $number];
            $snapshot = array_merge($input, $calculation, $metadata, [
                'id' => $id, 'created_by' => $actor->id,
                'quote_number' => $quoteNumber,
                'version_label' => 'V'.$number,
                'client_name' => $this->quotes->clientName($input['client_id']),
                'site_name' => $this->quotes->siteName($input['site_id']),
                'vat_withholding' => ['applied' => $rate > 0, 'rate_bps' => $rate, 'basis' => 'tax_total'],
                'clauses' => $clauses,
                'status' => 'draft', 'emission_allowed' => false,
                'blockers' => ['Aprobación humana pendiente.', 'Reglas comerciales, tributarias y datos oficiales pendientes de configurar.'],
                'created_at' => now()->toIso8601String(),
                'valid_until' => now('America/Bogota')->addDays($input['validity_days'])->toDateString(),
            ]);
            $this->quotes->create(array_merge($metadata, [
                'id' => $id, 'client_id' => $input['client_id'], 'site_id' => $input['site_id'],
                'created_by' => $actor->id, 'status' => 'draft', 'quote_number' => $quoteNumber,
                'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now(),
            ]));
            if (is_string($assistRequestId) && Str::isUuid($assistRequestId)) {
                // Ajena, ya enlazada o de más de 24 h: se ignora en silencio.
                $this->knowledge->linkAssistRequest($assistRequestId, $actor->id, $rootId ?? $id);
            }
            Audit::record($actor->id, $sourceId ? 'quote.revised' : 'quote.created', $id, array_filter([
                'source_id' => $sourceId, 'root_quote_id' => $rootId, 'revision_number' => $number, 'quote_number' => $quoteNumber,
            ]));

            return $snapshot;
        });
    }

    /**
     * Genera `free_line_id` en el servidor (nunca del cliente) y rechaza líneas libres cuya
     * descripción ya existe como ítem activo de la misma familia.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array<string, mixed>>
     */
    private function prepareFreeLines(array $lines, string $family): array
    {
        foreach ($lines as $index => $line) {
            if (($line['type'] ?? null) !== 'free') {
                continue;
            }
            $match = $this->catalog->activeExactMatch((string) $line['description'], $family);
            if ($match !== null) {
                throw ValidationException::withMessages(["lines.{$index}.description" => "Ya existe el ítem SKU {$match['sku']} activo con esa descripción; selecciónalo."]);
            }
            $lines[$index]['free_line_id'] = (string) Str::uuid();
        }

        return $lines;
    }
}
