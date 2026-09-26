<?php

namespace App\Http\Controllers;

use App\Application\Quotes\ListQuoteFollowups;
use App\Application\Quotes\RecordQuoteFollowup;
use App\Http\Requests\RecordQuoteFollowupRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class QuoteFollowupController extends Controller
{
    public function index(Request $request, string $id, ListQuoteFollowups $list): JsonResponse
    {
        return response()->json(['data' => $list->execute($id, $request->user())]);
    }

    public function store(RecordQuoteFollowupRequest $request, string $id, RecordQuoteFollowup $record): JsonResponse
    {
        return response()->json(['data' => $record->execute($id, $request->user(), $request->validated())], 201);
    }
}
