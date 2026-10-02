<?php

namespace App\Http\Controllers;

use App\Application\Quotes\ReviewQuote;
use App\Domain\Audit;
use App\Domain\Quotes\ApprovalValidation;
use App\Http\Requests\ReviewQuoteRequest;
use App\Repositories\Contracts\QuoteRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

    public function review(ReviewQuoteRequest $request, string $id, ReviewQuote $review): JsonResponse
    {
        $input = $request->validated();

        return response()->json(['data' => $review->execute($id, $request->user(), $input['decision'], $input['reason'], $input['confirm_new_items'] ?? null)]);
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
