<?php

namespace App\Http\Controllers;

use App\Domain\Audit;
use App\Domain\Quotes\ApprovalValidation;
use App\Repositories\Contracts\QuoteRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class QuoteReviewController extends Controller
{
    public function __construct(private QuoteRepository $quotes) {}

    public function submit(Request $request, string $id, ApprovalValidation $validation): JsonResponse
    {
        $input = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);

        return DB::transaction(function () use ($request, $id, $validation, $input) {
            $quote = $this->quotes->findVisible($id, $request->user(), true);
            abort_unless($quote, 404);
            abort_unless($quote->created_by, 422, 'Este borrador anterior no tiene autor. Crea uno nuevo.');
            abort_unless($quote->status === 'draft', 409, 'Solo se pueden enviar a revisión borradores pendientes.');
            $snapshot = json_decode($quote->snapshot, true, flags: JSON_THROW_ON_ERROR);
            $checked = $validation->check($snapshot, $quote, false);
            $this->transition($request, $id, 'in_review', 'submitted', $input['reason'], $checked);

            return response()->json(['data' => ['status' => 'in_review']]);
        });
    }

    public function review(Request $request, string $id, ApprovalValidation $validation): JsonResponse
    {
        $input = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'return'])],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ]);

        return DB::transaction(function () use ($request, $id, $validation, $input) {
            $quote = $this->quotes->findForReview($id);
            abort_unless($quote, 404);
            abort_unless($quote->status === 'in_review', 409, 'La cotización no está pendiente de revisión.');
            abort_if((int) $quote->created_by === $request->user()->id, 403, 'Otra persona debe revisar tu cotización.');
            $snapshot = json_decode($quote->snapshot, true, flags: JSON_THROW_ON_ERROR);
            $checked = $input['decision'] === 'approve' ? $validation->check($snapshot, $quote) : [];
            $status = $input['decision'] === 'approve' ? 'approved' : 'draft';
            $this->transition($request, $id, $status, $input['decision'], $input['reason'], $checked);

            return response()->json(['data' => ['status' => $status, 'validation' => $checked, 'emission_allowed' => false]]);
        });
    }

    private function transition(Request $request, string $id, string $status, string $decision, string $reason, array $validation): void
    {
        $this->quotes->transition($id, $status, [
            'quote_id' => $id, 'user_id' => $request->user()->id, 'decision' => $decision,
            'reason' => $reason, 'validation' => json_encode($validation, JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
        Audit::record($request->user()->id, 'quote.'.$decision, $id, ['status' => $status, 'reason' => $reason]);
    }
}
