<?php

namespace App\Http\Controllers;

use App\Application\Quotes\CreateClause;
use App\Application\Quotes\PublishClauseVersion;
use App\Application\Quotes\UpdateClause;
use App\Domain\QuoteFamily;
use App\Domain\Quotes\ClauseType;
use App\Http\Requests\CreateClauseRequest;
use App\Http\Requests\PublishClauseVersionRequest;
use App\Http\Requests\UpdateClauseRequest;
use App\Repositories\Contracts\ClauseRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ClauseController extends Controller
{
    public function __construct(private ClauseRepository $clauses) {}

    public function current(Request $request): JsonResponse
    {
        $input = $request->validate(['family' => ['required', Rule::in(QuoteFamily::values())]]);
        $rows = $this->clauses->currentForFamily($input['family']);

        return response()->json(['data' => $rows->map(fn ($row) => [
            'clause_id' => $row->clause_id, 'clause_version_id' => $row->clause_version_id, 'family' => $row->family,
            'type' => $row->type, 'title' => $row->title, 'is_default' => (bool) $row->is_default,
            'version' => (int) $row->version, 'origin' => $row->origin, 'body' => $row->body,
        ])->values()]);
    }

    public function index(Request $request): JsonResponse
    {
        $input = $request->validate([
            'family' => ['nullable', Rule::in(QuoteFamily::values())],
            'type' => ['nullable', Rule::in(ClauseType::values())],
            'include_inactive' => ['nullable', 'boolean'],
        ]);

        return response()->json(['data' => $this->clauses->adminList(
            $input['family'] ?? null,
            $input['type'] ?? null,
            (bool) ($input['include_inactive'] ?? false)
        )->values()]);
    }

    public function show(string $id): JsonResponse
    {
        $detail = $this->clauses->adminDetail($id);
        abort_unless($detail, 404);

        return response()->json(['data' => $detail]);
    }

    public function store(CreateClauseRequest $request, CreateClause $createClause): JsonResponse
    {
        $clause = $createClause->execute($request->validated(), $request->user());

        return response()->json(['data' => $clause], 201);
    }

    public function publish(PublishClauseVersionRequest $request, string $id, PublishClauseVersion $publish): JsonResponse
    {
        $version = $publish->execute($id, $request->validated(), $request->user());
        abort_unless($version, 404);

        return response()->json(['data' => $version], 201);
    }

    public function update(UpdateClauseRequest $request, string $id, UpdateClause $update): JsonResponse
    {
        $clause = $update->execute($id, $request->validated(), $request->user());
        abort_unless($clause, 404);

        return response()->json(['data' => $clause]);
    }
}
