<?php

namespace App\Http\Controllers;

use App\Application\Quotes\SuggestQuoteLines;
use App\Http\Requests\LineSuggestionsRequest;
use Illuminate\Http\JsonResponse;

final class QuoteLineSuggestionController extends Controller
{
    public function __invoke(LineSuggestionsRequest $request, SuggestQuoteLines $suggest): JsonResponse
    {
        $data = $suggest->execute((string) $request->validated('q'), $request->validated('family'));

        return response()->json(['data' => $data], 200, ['Cache-Control' => 'private, no-store']);
    }
}
