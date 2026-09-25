<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class QuoteApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 9, 18)->startOfDay());
        $this->seed(DemoSeeder::class);
    }

    private function payload(): array
    {
        return [
            'client_id' => '00000000-0000-4000-8000-000000000001',
            'site_id' => '00000000-0000-4000-8000-000000000002',
            'lines' => [['price_version_id' => '00000000-0000-4000-8000-000000000004', 'quantity' => '8', 'discount_bps' => 0]],
            'family' => 'cctv',
            'scope' => 'Instalar ocho cámaras IP.', 'exclusions' => 'Obra civil no incluida.',
            'payment_terms' => '50 % anticipo / 50 % entrega.', 'warranty' => 'Pendiente de validación.',
            'validity_terms' => 'Esta propuesta tiene una vigencia de 15 días calendario.', 'validity_days' => 15,
        ];
    }

    private function authorizeApi(): static
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'quoter', 'active' => true]));

        return $this;
    }

    public function test_api_requires_a_token(): void
    {
        $this->getJson('/api/v1/catalog')->assertUnauthorized();
        $this->withToken(str_repeat('t', 32))->getJson('/api/v1/catalog')->assertUnauthorized();
    }

    public function test_can_create_and_retrieve_a_draft_with_server_totals(): void
    {
        $response = $this->authorizeApi()->postJson('/api/v1/quotes', $this->payload())
            ->assertCreated()->assertJsonPath('data.totals.total', '1904000.00')
            ->assertJsonPath('data.status', 'draft')->assertJsonPath('data.emission_allowed', false);
        $this->getJson('/api/v1/quotes/'.$response->json('data.id'))->assertOk()->assertJsonPath('data.totals.total', '1904000.00');
        $this->assertDatabaseCount('quotes', 1);
    }

    public function test_cannot_inject_a_price(): void
    {
        $input = $this->payload();
        $input['lines'][0]['price_cents'] = 1;
        $this->authorizeApi()->postJson('/api/v1/quotes', $input)->assertUnprocessable();
        $this->assertDatabaseCount('quotes', 0);
    }

    public function test_expired_price_is_blocked(): void
    {
        DB::table('price_versions')->update(['valid_until' => '2026-09-17']);
        $this->authorizeApi()->postJson('/api/v1/quotes', $this->payload())->assertUnprocessable();
        $this->getJson('/api/v1/catalog')->assertJsonCount(0, 'data');
    }

    public function test_last_day_of_validity_is_inclusive(): void
    {
        DB::table('price_versions')->update(['valid_until' => '2026-09-18']);
        $this->authorizeApi()->postJson('/api/v1/quotes', $this->payload())->assertCreated();
    }

    public function test_historical_price_is_blocked(): void
    {
        DB::table('price_versions')->update(['status' => 'historical']);
        $this->authorizeApi()->postJson('/api/v1/quotes', $this->payload())->assertUnprocessable();
    }

    public function test_future_price_is_blocked(): void
    {
        DB::table('price_versions')->update(['valid_from' => '2026-09-19']);
        $this->authorizeApi()->postJson('/api/v1/quotes', $this->payload())->assertUnprocessable();
    }

    public function test_site_must_belong_to_client(): void
    {
        DB::table('clients')->insert(['id' => '00000000-0000-4000-8000-000000000009', 'name' => 'Otro cliente']);
        $input = $this->payload();
        $input['client_id'] = '00000000-0000-4000-8000-000000000009';
        $this->authorizeApi()->postJson('/api/v1/quotes', $input)->assertUnprocessable()->assertJsonValidationErrors('site_id');
    }

    public function test_inactive_item_is_blocked(): void
    {
        DB::table('catalog_items')->update(['active' => false]);
        $this->authorizeApi()->postJson('/api/v1/quotes', $this->payload())->assertUnprocessable();
    }

    public function test_missing_terms_and_zero_quantity_are_rejected(): void
    {
        $input = $this->payload();
        unset($input['payment_terms']);
        $this->authorizeApi()->postJson('/api/v1/quotes', $input)->assertUnprocessable();
        $input = $this->payload();
        $input['lines'][0]['quantity'] = '000.000';
        $this->postJson('/api/v1/quotes', $input)->assertUnprocessable();
        $this->assertDatabaseCount('quotes', 0);
    }

    public function test_saved_snapshot_does_not_change_with_catalog(): void
    {
        $id = $this->authorizeApi()->postJson('/api/v1/quotes', $this->payload())->assertCreated()->json('data.id');
        DB::table('price_versions')->update(['price_cents' => 999]);
        $this->getJson('/api/v1/quotes/'.$id)->assertJsonPath('data.totals.total', '1904000.00');
    }

    public function test_preview_calculates_without_persisting_a_draft(): void
    {
        $this->authorizeApi()->postJson('/api/v1/quotes/preview', ['lines' => $this->payload()['lines']])
            ->assertOk()->assertJsonPath('data.totals.total', '1904000.00');
        $this->assertDatabaseCount('quotes', 0);
    }

    public function test_preview_rejects_expired_prices_and_price_injection(): void
    {
        $input = ['lines' => $this->payload()['lines']];
        $input['lines'][0]['price_cents'] = 1;
        $this->authorizeApi()->postJson('/api/v1/quotes/preview', $input)->assertUnprocessable();
        DB::table('price_versions')->update(['valid_until' => '2026-09-17']);
        $this->postJson('/api/v1/quotes/preview', ['lines' => $this->payload()['lines']])->assertUnprocessable();
        $this->assertDatabaseCount('quotes', 0);
    }

    public function test_list_is_paginated_and_includes_saved_names(): void
    {
        $this->authorizeApi()->postJson('/api/v1/quotes', $this->payload())->assertCreated()
            ->assertJsonPath('data.client_name', 'Cliente de demostración')
            ->assertJsonPath('data.site_name', 'Sede de prueba');
        DB::table('clients')->update(['name' => 'Nombre actualizado']);
        $this->getJson('/api/v1/quotes')->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('per_page', 20)->assertJsonPath('data.0.client_name', 'Cliente de demostración')
            ->assertJsonPath('data.0.total', '1904000.00');
    }

    public function test_quoters_only_see_owned_drafts_and_legacy_drafts_require_elevated_role(): void
    {
        $id = $this->authorizeApi()->postJson('/api/v1/quotes', $this->payload())->assertCreated()->json('data.id');
        $this->authorizeApi()->getJson('/api/v1/quotes/'.$id)->assertNotFound();
        $this->getJson('/api/v1/quotes')->assertOk()->assertJsonPath('total', 0);
        DB::table('quotes')->where('id', $id)->update(['created_by' => null]);
        $this->getJson('/api/v1/quotes/'.$id)->assertNotFound();
        foreach (['admin', 'approver'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role, 'active' => true]));
            $this->getJson('/api/v1/quotes/'.$id)->assertOk();
            $this->getJson('/api/v1/quotes')->assertOk()->assertJsonPath('total', 1);
        }
    }

    public function test_quoter_responses_redact_costs_but_authorized_review_preserves_them(): void
    {
        $preview = $this->authorizeApi()->postJson('/api/v1/quotes/preview', ['lines' => $this->payload()['lines']])->assertOk();
        $stored = $this->postJson('/api/v1/quotes', $this->payload())->assertCreated();
        $id = $stored->json('data.id');
        $shown = $this->getJson('/api/v1/quotes/'.$id)->assertOk();
        foreach ([$preview, $stored, $shown] as $response) {
            $response->assertJsonMissingPath('data.totals.cost')->assertJsonMissingPath('data.profit')
                ->assertJsonMissingPath('data.lines.0.cost_cents')->assertJsonMissingPath('data.lines.0.amounts.cost');
        }
        Sanctum::actingAs(User::factory()->create(['role' => 'approver', 'active' => true]));
        $this->getJson('/api/v1/quotes/'.$id)->assertOk()->assertJsonStructure(['data' => ['totals' => ['cost'], 'lines' => [['cost_cents']]]]);
    }

    public function test_cannot_inject_quote_number_withholding_rate_or_totals_on_save(): void
    {
        $input = $this->payload();
        $input['quote_number'] = 'COT-2026-9999';
        $input['withholds_vat'] = true;
        $input['vat_withholding'] = ['applied' => true, 'rate_bps' => 9999, 'basis' => 'tax_total'];
        $input['totals'] = ['total' => '1.00', 'payable' => '1.00', 'tax' => '1.00'];
        $input['status'] = 'approved';
        $input['emission_allowed'] = true;

        $response = $this->authorizeApi()->postJson('/api/v1/quotes', $input)->assertCreated();
        $response->assertJsonPath('data.quote_number', 'COT-2026-0001')
            ->assertJsonPath('data.vat_withholding.applied', false)
            ->assertJsonPath('data.vat_withholding.rate_bps', 0)
            ->assertJsonPath('data.totals.total', '1904000.00')
            ->assertJsonPath('data.totals.payable', '1904000.00')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.emission_allowed', false);

        $stored = DB::table('quotes')->find($response->json('data.id'));
        $this->assertSame('draft', $stored->status);
        $this->assertNotSame('COT-2026-9999', $stored->quote_number);
    }

    public function test_cannot_inject_totals_or_withholding_on_preview(): void
    {
        $input = ['lines' => $this->payload()['lines']];
        $input['vat_withholding'] = ['applied' => true, 'rate_bps' => 9999];
        $input['totals'] = ['total' => '1.00', 'payable' => '1.00'];

        $this->authorizeApi()->postJson('/api/v1/quotes/preview', $input)->assertOk()
            ->assertJsonPath('data.vat_withholding.applied', false)
            ->assertJsonPath('data.totals.total', '1904000.00')
            ->assertJsonPath('data.totals.payable', '1904000.00');
    }
}
