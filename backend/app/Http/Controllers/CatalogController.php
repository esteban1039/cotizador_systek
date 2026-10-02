<?php

namespace App\Http\Controllers;

use App\Domain\Audit;
use App\Domain\DecimalMoney;
use App\Domain\QuoteFamily;
use App\Http\Requests\SimilarCatalogRequest;
use App\Repositories\Contracts\CatalogRepository;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class CatalogController extends Controller
{
    public function __construct(private readonly CatalogRepository $catalog) {}

    public function current(): JsonResponse
    {
        return response()->json(['data' => $this->catalog->currentPrices(now()->toDateString())]);
    }

    public function similar(SimilarCatalogRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->catalog->similarItems($request->validated('q'), $request->validated('family'), 8)]);
    }

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->catalog->catalogWithHistory()]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->merge(['sku' => strtoupper(trim($request->input('sku', '')))]);
        $input = $request->validate([
            'sku' => ['required', 'string', 'max:60', 'regex:/^[A-Z0-9_-]+$/D', function (string $attribute, mixed $value, Closure $fail): void {
                if ($this->catalog->skuExists($value)) {
                    $fail('validation.unique')->translate(['attribute' => $attribute]);
                }
            }],
            'description' => ['required', 'string', 'max:255'],
            'family' => ['required', Rule::in(QuoteFamily::values())],
            'unit' => ['required', Rule::in(['unidad', 'metro', 'hora', 'servicio', 'licencia'])],
        ]);
        $id = (string) Str::uuid();
        $item = DB::transaction(function () use ($request, $input, $id) {
            $item = $this->catalog->createItem(array_merge($input, ['id' => $id, 'active' => true, 'is_demo' => false]));
            Audit::record($request->user()->id, 'catalog.created', $id);

            return $item;
        });

        return response()->json(['data' => $item], 201);
    }

    public function publish(Request $request, string $id): JsonResponse
    {
        $input = $request->validate([
            'price' => ['required', 'string'], 'cost' => ['required', 'string'],
            'tax_bps' => ['required', 'integer', 'between:0,10000'],
            'valid_until' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);
        $price = DecimalMoney::cents($input['price'], 'price');
        $cost = DecimalMoney::cents($input['cost'], 'cost');

        return DB::transaction(function () use ($request, $id, $input, $price, $cost) {
            $record = $this->catalog->publishPrice($id, [
                'id' => (string) Str::uuid(),
                'price_cents' => $price, 'cost_cents' => $cost, 'tax_bps' => $input['tax_bps'],
                'valid_from' => now()->toDateString(), 'valid_until' => $input['valid_until'],
                'created_at' => now(), 'updated_at' => now(),
            ]);
            abort_unless($record, 404);
            Audit::record($request->user()->id, 'price.published', $record['id'], ['catalog_item_id' => $id, 'version' => $record['version'], 'reason' => $input['reason']]);

            return response()->json(['data' => $record], 201);
        });
    }

    public function active(Request $request, string $id): JsonResponse
    {
        $input = $request->validate(['active' => ['required', 'boolean']]);

        return DB::transaction(function () use ($request, $input, $id) {
            abort_unless($this->catalog->setActive($id, (bool) $input['active']), 404);
            Audit::record($request->user()->id, 'catalog.active_changed', $id, $input);

            return response()->json(['data' => $input]);
        });
    }

    public function rules(): JsonResponse
    {
        return response()->json(['data' => $this->catalog->commercialRules()]);
    }

    public function saveRule(Request $request): JsonResponse
    {
        $input = $request->validate([
            'family' => ['required', Rule::in(QuoteFamily::values())],
            'minimum_margin_bps' => ['required', 'integer', 'between:0,10000'],
            'max_discount_bps' => ['required', 'integer', 'between:0,10000'],
            'review_above' => ['required', 'string'], 'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);
        $values = ['minimum_margin_bps' => $input['minimum_margin_bps'], 'max_discount_bps' => $input['max_discount_bps'], 'review_above_cents' => DecimalMoney::cents($input['review_above'], 'review_above', 100000000000000), 'updated_at' => now()];
        DB::transaction(function () use ($request, $input, $values) {
            $this->catalog->saveCommercialRule($input['family'], $values);
            Audit::record($request->user()->id, 'rule.configured', $input['family'], array_merge($values, ['reason' => $input['reason']]));
        });

        return response()->json(['data' => $values]);
    }
}
