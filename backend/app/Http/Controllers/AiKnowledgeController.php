<?php

namespace App\Http\Controllers;

use App\Application\Quotes\ReviewKnowledgeEntry;
use App\Domain\QuoteFamily;
use App\Http\Requests\ReviewKnowledgeRequest;
use App\Repositories\Contracts\KnowledgeRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Pantalla de administración de la base de conocimiento del asistente (§9.3). Solo administradores. */
final class AiKnowledgeController extends Controller
{
    private const HEADERS = ['Cache-Control' => 'private, no-store'];

    public function __construct(private readonly KnowledgeRepository $knowledge) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['active', 'needs_review', 'excluded'])],
            'source' => ['nullable', Rule::in(['approved_quote', 'drive_import'])],
            'family' => ['nullable', Rule::in(QuoteFamily::values())],
            'q' => ['nullable', 'string', 'max:120'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
        ]);

        return response()->json($this->knowledge->paginate($filters), 200, self::HEADERS);
    }

    public function metrics(): JsonResponse
    {
        return response()->json(['data' => $this->knowledge->metrics()], 200, self::HEADERS);
    }

    public function show(string $id): JsonResponse
    {
        $entry = $this->knowledge->find($id);
        abort_if($entry === null, 404);

        return response()->json(['data' => $entry], 200, self::HEADERS);
    }

    public function update(ReviewKnowledgeRequest $request, string $id, ReviewKnowledgeEntry $review): JsonResponse
    {
        $entry = $review->execute($id, (int) $request->user()->id, $request->review());
        abort_if($entry === null, 404);

        return response()->json(['data' => $entry], 200, self::HEADERS);
    }
}
