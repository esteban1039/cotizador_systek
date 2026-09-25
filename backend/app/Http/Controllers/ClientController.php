<?php

namespace App\Http\Controllers;

use App\Domain\Audit;
use App\Http\Requests\TaxProfileRequest;
use App\Repositories\Contracts\ClientRepository;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ClientController extends Controller
{
    public function __construct(private readonly ClientRepository $clients) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->clients->directory()]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate(['nit' => ['nullable', 'string', 'regex:/^[0-9.\s-]+$/D']]);
        $nit = $request->filled('nit') ? preg_replace('/\D/', '', $request->input('nit')) : null;
        $request->merge(['nit' => $nit]);
        $input = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'nit' => ['nullable', 'regex:/^\d{6,15}$/D', function (string $attribute, mixed $value, Closure $fail): void {
                if ($this->clients->nitExists($value)) {
                    $fail('Ya existe un cliente con este NIT. Revisa la coincidencia; no se fusionaron datos.');
                }
            }],
        ]);
        $id = (string) Str::uuid();
        $client = DB::transaction(function () use ($input, $id, $request) {
            $client = $this->clients->createClient(array_merge($input, ['id' => $id, 'is_demo' => false]));
            Audit::record($request->user()->id, 'client.created', $id);

            return $client;
        });

        return response()->json(['data' => $client], 201);
    }

    public function site(Request $request, string $id): JsonResponse
    {
        abort_unless($this->clients->exists($id), 404);
        $input = $request->validate(['name' => ['required', 'string', 'max:255'], 'city' => ['required', 'string', 'max:255'], 'address' => ['nullable', 'string', 'max:255']]);
        $siteId = (string) Str::uuid();
        $site = DB::transaction(function () use ($request, $id, $input, $siteId) {
            $site = $this->clients->createSite($id, array_merge($input, ['id' => $siteId]));
            Audit::record($request->user()->id, 'sites.created', $siteId, ['client_id' => $id]);

            return $site;
        });

        return response()->json(['data' => $site], 201);
    }

    public function contact(Request $request, string $id): JsonResponse
    {
        abort_unless($this->clients->exists($id), 404);
        $input = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'required_without:phone', 'email', 'max:255'],
            'phone' => ['nullable', 'required_without:email', 'string', 'max:40'],
        ]);
        $contactId = (string) Str::uuid();
        $contact = DB::transaction(function () use ($request, $id, $input, $contactId) {
            $contact = $this->clients->createContact($id, array_merge($input, ['id' => $contactId]));
            Audit::record($request->user()->id, 'contacts.created', $contactId, ['client_id' => $id]);

            return $contact;
        });

        return response()->json(['data' => $contact], 201);
    }

    public function taxProfile(TaxProfileRequest $request, string $id): JsonResponse
    {
        $input = $request->validated();

        return DB::transaction(function () use ($request, $id, $input) {
            $result = $this->clients->updateTaxProfile($id, (bool) $input['withholds_vat']);
            abort_unless($result, 404);
            Audit::record($request->user()->id, 'client.tax_profile_changed', $id, [
                'previous' => $result['previous'], 'withholds_vat' => $result['withholds_vat'], 'reason' => $input['reason'],
            ]);

            return response()->json(['data' => ['id' => $id, 'withholds_vat' => $result['withholds_vat']]]);
        });
    }
}
