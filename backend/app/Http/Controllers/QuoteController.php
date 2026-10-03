<?php

namespace App\Http\Controllers;

use App\Application\Quotes\CreateQuote;
use App\Domain\CompanyProfile;
use App\Domain\Quotes\ApprovalValidation;
use App\Domain\Quotes\EmissionPolicy;
use App\Domain\Quotes\FollowupPolicy;
use App\Domain\Quotes\QuotePricer;
use App\Domain\Quotes\QuoteVisibility;
use App\Domain\Quotes\SnapshotCompatibility;
use App\Domain\Quotes\VatWithholdingPolicy;
use App\Http\Requests\CalculateQuoteRequest;
use App\Http\Requests\PreviewQuoteRequest;
use App\Repositories\Contracts\CatalogRepository;
use App\Repositories\Contracts\ClientRepository;
use App\Repositories\Contracts\CompanyRepository;
use App\Repositories\Contracts\QuoteEmissionRepository;
use App\Repositories\Contracts\QuoteFollowupRepository;
use App\Repositories\Contracts\QuoteRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class QuoteController extends Controller
{
    public function __construct(private QuoteRepository $quotes, private CatalogRepository $catalog, private CompanyRepository $companies, private QuoteEmissionRepository $emissions, private EmissionPolicy $policy, private QuoteFollowupRepository $followups, private FollowupPolicy $followupPolicy) {}

    public function store(CalculateQuoteRequest $request, CreateQuote $create): JsonResponse
    {
        $snapshot = $create->execute($request->validated(), $request->user());

        return response()->json(['data' => QuoteVisibility::redact($snapshot, $request->user())], 201);
    }

    public function revise(CalculateQuoteRequest $request, string $id, CreateQuote $create): JsonResponse
    {
        $snapshot = $create->execute($request->validated(), $request->user(), $id);

        return response()->json(['data' => QuoteVisibility::redact($snapshot, $request->user())], 201);
    }

    public function preview(PreviewQuoteRequest $request, QuotePricer $pricer, ClientRepository $clients): JsonResponse
    {
        return response()->json(['data' => DB::transaction(function () use ($request, $pricer, $clients) {
            $clientId = $request->validated('client_id');
            $withholds = $clientId ? (bool) $clients->lockedTaxProfile($clientId) : false;
            $rate = VatWithholdingPolicy::rateFor($withholds);
            $calculation = $pricer->calculate($request->validated('lines'), $rate);
            $calculation['vat_withholding'] = ['applied' => $rate > 0, 'rate_bps' => $rate, 'basis' => 'tax_total'];

            return QuoteVisibility::redact($calculation, $request->user());
        })]);
    }

    public function index(Request $request): JsonResponse
    {
        $quotes = $this->quotes->visiblePage($request->user());
        $quotes->through(function ($quote) {
            $snapshot = json_decode($quote->snapshot, true, flags: JSON_THROW_ON_ERROR);
            $snapshot = SnapshotCompatibility::normalize($snapshot, $quote);

            return [
                'id' => $quote->id,
                'quote_number' => $quote->quote_number,
                'version_label' => 'V'.(int) $quote->revision_number,
                'root_quote_id' => $quote->root_quote_id,
                'previous_quote_id' => $quote->previous_quote_id,
                'revision_number' => (int) $quote->revision_number,
                'client_name' => $snapshot['client_name'] ?? $this->quotes->clientName($quote->client_id),
                'scope' => $snapshot['scope'],
                'total' => $snapshot['totals']['total'],
                'payable' => $snapshot['totals']['payable'],
                'status' => $quote->status,
                'created_at' => $quote->created_at,
                'valid_until' => $snapshot['valid_until'],
            ];
        });

        return response()->json($quotes);
    }

    public function show(Request $request, string $id, ApprovalValidation $validation): JsonResponse
    {
        $quote = $this->quotes->findVisible($id, $request->user());
        abort_unless($quote, 404);

        $snapshot = json_decode($quote->snapshot, true, flags: JSON_THROW_ON_ERROR);
        $snapshot = SnapshotCompatibility::normalize($snapshot, $quote);
        $links = $this->quotes->freeLineLinks($id);
        if ($links !== []) {
            $skus = $this->catalog->skusByIds(array_column($links, 'catalog_item_id'));
            foreach ($snapshot['lines'] as &$line) {
                $link = ($line['line_type'] ?? 'catalog') === 'free' ? ($links[$line['free_line_id'] ?? ''] ?? null) : null;
                if ($link !== null) {
                    $line['linked_item'] = ['catalog_item_id' => $link['catalog_item_id'], 'sku' => $skus[$link['catalog_item_id']] ?? null, 'price_version_id' => $link['price_version_id']];
                }
            }
            unset($line);
        }
        $snapshot['root_quote_id'] = $quote->root_quote_id;
        $snapshot['previous_quote_id'] = $quote->previous_quote_id;
        $snapshot['revision_number'] = (int) $quote->revision_number;
        $snapshot['version_label'] = 'V'.(int) $quote->revision_number;
        $snapshot['quote_number'] = $quote->quote_number;
        $snapshot['revisions'] = $this->quotes->visibleRevisions($quote->root_quote_id ?? $quote->id, $request->user());
        $snapshot['status'] = $quote->status;
        $snapshot['created_by'] = $quote->created_by;
        $snapshot['can_revise'] = in_array($request->user()->role, ['admin', 'quoter'], true);
        $snapshot['can_submit'] = $quote->status === 'draft' && $quote->created_by && in_array($request->user()->role, ['admin', 'quoter'], true);
        $snapshot['can_review'] = $quote->status === 'in_review' && (int) $quote->created_by !== $request->user()->id && in_array($request->user()->role, ['admin', 'approver'], true);
        $snapshot['approval_errors'] = [];
        $snapshot['review_flags'] = [];
        if ($snapshot['can_review'] || $snapshot['can_submit']) {
            try {
                $checked = DB::transaction(fn () => $validation->check($snapshot, $quote, $snapshot['can_review']));
                $snapshot['review_flags'] = $checked['flags'];
            } catch (ValidationException $exception) {
                $snapshot['approval_errors'] = array_merge(...array_values($exception->errors()));
            }
        }
        $snapshot['reviews'] = $this->quotes->reviews($id);

        $profile = $this->companies->currentProfile();
        $missingCompany = CompanyProfile::missing($profile === null ? null : array_merge(
            Arr::only($profile, ['legal_name', 'nit', 'address', 'phone', 'email', 'signer_name', 'signer_title']),
            ['bank_account' => $profile['bank_account_configured'] ?? false]
        ));
        $snapshot['issuer'] = [
            'complete' => $missingCompany === [],
            'missing' => $missingCompany,
            'legal_name' => $profile['legal_name'] ?? null,
            'trade_name' => $profile['trade_name'] ?? null,
            'nit' => $profile['nit'] ?? null,
            'address' => $profile['address'] ?? null,
            'phone' => $profile['phone'] ?? null,
            'email' => $profile['email'] ?? null,
            'website' => $profile['website'] ?? null,
        ];

        $blockers = $quote->status === 'approved'
            ? ['Revisión comercial registrada. La emisión PDF y los datos legales oficiales siguen pendientes.']
            : ['Revisión comercial pendiente.', 'La emisión PDF y los datos legales oficiales siguen pendientes.'];
        if ($missingCompany !== []) {
            $blockers[] = 'Empresa emisora incompleta: faltan '.implode(', ', $missingCompany).'. La emisión oficial seguirá bloqueada.';
        }
        $snapshot['blockers'] = $blockers;

        $rootId = $quote->root_quote_id ?? $quote->id;
        $approval = $quote->status === 'approved' ? $this->quotes->approvingReview($id) : null;
        $issueBlockers = $this->policy->blockers([
            'status' => $quote->status, 'role' => $request->user()->role, 'actor_id' => $request->user()->id,
            'author_id' => $quote->created_by === null ? null : (int) $quote->created_by,
            'requires_authorization' => (bool) ($profile['emission_requires_authorization'] ?? true),
            'is_latest' => (int) $quote->revision_number >= $this->quotes->latestRevisionNumber($rootId),
            'company_missing' => $missingCompany, 'valid_until' => $snapshot['valid_until'],
            'today' => now('America/Bogota')->toDateString(),
            'approver_id' => $approval === null ? null : (int) $approval->user_id, 'approval_auto' => (bool) ($approval->auto_approved ?? false), 'quote_number' => $quote->quote_number,
        ]);
        $snapshot['can_issue'] = $issueBlockers === [];
        $snapshot['issue_blockers'] = $issueBlockers;
        $snapshot['emission_allowed'] = $snapshot['can_issue'];
        $snapshot['emission'] = $this->emissions->forQuote($id);
        $issued = $quote->status === 'issued';
        $snapshot['commercial_status'] = $issued ? $this->followupPolicy->commercialStatus($this->followups->forQuote($id)->pluck('type')->all()) : null;
        $snapshot['can_record_followup'] = $issued && $this->followupPolicy->mayRecord($request->user()->role, $request->user()->id, $quote->created_by === null ? null : (int) $quote->created_by);

        return response()->json(['data' => QuoteVisibility::redact($snapshot, $request->user(), true)]);
    }
}
