<?php

namespace App\Http\Controllers;

use App\Application\Quotes\AssistantDisabled;
use App\Application\Quotes\AssistantFailed;
use App\Application\Quotes\ProposeQuoteDraft;
use App\Http\Requests\AssistQuoteRequest;
use Illuminate\Http\JsonResponse;

final class QuoteAssistController extends Controller
{
    public function __invoke(AssistQuoteRequest $request, ProposeQuoteDraft $propose): JsonResponse
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        try {
            $data = $propose->execute($request->user(), (string) $request->validated('text'), $request->validated('family'));
        } catch (AssistantDisabled) {
            return response()->json(['message' => 'El asistente está desactivado.', 'code' => 'assistant_disabled'], 503, $headers);
        } catch (AssistantFailed) {
            return response()->json(['message' => 'El asistente no está disponible. Intenta de nuevo.', 'code' => 'assistant_unavailable'], 502, $headers);
        }

        return response()->json(['data' => $data], 200, $headers);
    }
}
