<?php

namespace App\Http\Controllers;

use App\Domain\Audit;
use App\Domain\QuoteFamily;
use App\Repositories\Contracts\ClientRepository;
use App\Repositories\Contracts\HistoryRepository;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class HistoryController extends Controller
{
    public function __construct(private readonly HistoryRepository $history, private readonly ClientRepository $clients) {}

    public function index(Request $request): JsonResponse
    {
        $input = $request->validate(['q' => ['nullable', 'string', 'max:255'], 'status' => ['nullable', Rule::in(['pending', 'approved', 'rejected'])], 'page' => ['sometimes', 'integer', 'min:1', 'max:100000']]);

        return response()->json($this->history->paginate($input['q'] ?? null, $input['status'] ?? null));
    }

    public function show(string $id): JsonResponse
    {
        $document = $this->history->find($id);
        abort_unless($document, 404);

        $document['sources'] = $this->history->sources($id);

        return response()->json(['data' => $document, 'candidates' => $this->history->candidates($document['client_nit'], $document['client_name'])]);
    }

    public function import(Request $request): JsonResponse
    {
        abort_if(strlen($request->getContent()) > 1048576, 413, 'El lote supera 1 MiB.');
        $input = $request->validate([
            'records' => ['required', 'array', 'min:1', 'max:20'],
            'records.*' => ['required', 'array:source_id,title,source_url,client_name,client_nit,family,issued_on,source_text'],
            'records.*.source_id' => ['required', 'string', 'max:255'],
            'records.*.title' => ['required', 'string', 'max:255'],
            'records.*.source_url' => ['nullable', 'string', 'max:2048', 'url:https', function ($attribute, $value, $fail) {
                $url = parse_url($value);
                if (! is_array($url) || ($url['scheme'] ?? '') !== 'https' || ! in_array(strtolower($url['host'] ?? ''), ['drive.google.com', 'docs.google.com'], true) || isset($url['user']) || isset($url['pass']) || (isset($url['port']) && $url['port'] !== 443)) {
                    $fail('La fuente debe ser una URL HTTPS de Google Drive o Docs sin credenciales.');
                }
            }],
            'records.*.client_name' => ['nullable', 'string', 'max:255'],
            'records.*.client_nit' => ['nullable', 'string', 'max:40', 'regex:/^[0-9.\s-]+$/D'],
            'records.*.family' => ['nullable', Rule::in(QuoteFamily::values())],
            'records.*.issued_on' => ['nullable', 'date_format:Y-m-d'],
            'records.*.source_text' => ['required', 'string', 'max:20000'],
        ]);
        try {
            $result = DB::transaction(function () use ($input, $request) {
                $imported = [];
                $duplicates = [];
                foreach ($input['records'] as $record) {
                    $text = trim(str_replace(["\r\n", "\r"], "\n", $record['source_text']));
                    $hash = hash('sha256', $text);
                    $source = $this->history->findSource($record['source_id']);
                    abort_if($source && $source['content_hash'] !== $hash, 409, 'Una fuente existente tiene contenido diferente. No se importó el lote.');
                    $duplicate = $source ?? $this->history->findHash($hash);
                    if ($duplicate) {
                        if (! $source) {
                            $this->history->registerSource(['source_id' => $record['source_id'], 'document_id' => $duplicate['id'], 'title' => $record['title'], 'source_url' => $record['source_url'] ?? null, 'imported_by' => $request->user()->id]);
                        }
                        $duplicates[] = ['id' => $duplicate['id'], 'source_id' => $record['source_id']];

                        continue;
                    }
                    $id = (string) Str::uuid();
                    $document = $this->history->create([...$record, 'id' => $id, 'source_text' => $text, 'content_hash' => $hash, 'status' => 'pending', 'imported_by' => $request->user()->id]);
                    $this->history->registerSource(['source_id' => $record['source_id'], 'document_id' => $id, 'title' => $record['title'], 'source_url' => $record['source_url'] ?? null, 'imported_by' => $request->user()->id]);
                    $imported[] = array_intersect_key($document, array_flip(['id', 'title', 'source_id', 'status']));
                }
                Audit::record($request->user()->id, 'history.imported', (string) Str::uuid(), ['document_ids' => array_column($imported, 'id'), 'imported_count' => count($imported), 'duplicate_count' => count($duplicates)]);

                return compact('imported', 'duplicates');
            });
        } catch (UniqueConstraintViolationException) {
            abort(409, 'Otra importación registró una fuente del lote. Reintenta para comprobar duplicados.');
        }

        return response()->json($result, 201);
    }

    public function review(Request $request, string $id): JsonResponse
    {
        $input = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'client_id' => ['nullable', 'required_if:decision,approved', 'uuid'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        if (isset($input['client_id'])) {
            abort_unless($this->clients->exists($input['client_id']), 422, 'El cliente no existe.');
        }
        $document = DB::transaction(function () use ($request, $id, $input) {
            $document = $this->history->find($id, true);
            abort_unless($document, 404);
            abort_unless($document['status'] === 'pending', 409, 'El documento ya fue revisado.');
            $document = $this->history->review($id, ['status' => $input['decision'], 'linked_client_id' => $input['decision'] === 'approved' ? $input['client_id'] : null, 'review_reason' => $input['reason'], 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);
            Audit::record($request->user()->id, 'history.'.$input['decision'], $id, ['client_id' => $document['linked_client_id'], 'reason' => $input['reason']]);

            return $document;
        });

        return response()->json(['data' => $document]);
    }
}
