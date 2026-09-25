<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Numeración `COT-AAAA-####` (diseño-iteracion-11.md §11, "Numeración"),
 * ejercida a través del flujo real de creación/revisión, a diferencia de
 * `QuoteNumberBackfillTest` (migración) y `QuoteNumberConcurrencyTest`
 * (bloqueo de fila en pgsql).
 */
final class QuoteNumberingIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create(['role' => 'quoter', 'active' => true]));
    }

    /** Los precios de `DemoSeeder` son válidos 15 días desde el momento del seed. */
    private function seedAt(\DateTimeInterface $date): void
    {
        $this->travelTo($date);
        $this->seed(DemoSeeder::class);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'client_id' => '00000000-0000-4000-8000-000000000001', 'site_id' => '00000000-0000-4000-8000-000000000002',
            'lines' => [['price_version_id' => '00000000-0000-4000-8000-000000000004', 'quantity' => '8', 'discount_bps' => 0]],
            'family' => 'cctv',
            'scope' => 'Ocho cámaras.', 'exclusions' => 'Sin obra civil.', 'payment_terms' => 'Contado.', 'warranty' => 'Por confirmar.',
            'validity_terms' => 'Vigencia de 15 días calendario.', 'validity_days' => 15,
        ], $overrides);
    }

    public function test_first_and_second_root_quotes_get_sequential_numbers_and_revision_inherits_it(): void
    {
        $this->seedAt(now()->setDate(2026, 9, 18)->startOfDay());

        $first = $this->postJson('/api/v1/quotes', $this->payload())->assertCreated()
            ->assertJsonPath('data.quote_number', 'COT-2026-0001')
            ->assertJsonPath('data.version_label', 'V1')->json('data.id');

        $second = $this->postJson('/api/v1/quotes', $this->payload())->assertCreated()
            ->assertJsonPath('data.quote_number', 'COT-2026-0002')
            ->assertJsonPath('data.version_label', 'V1')->json('data.id');

        $this->postJson("/api/v1/quotes/{$first}/revisions", $this->payload())->assertCreated()
            ->assertJsonPath('data.quote_number', 'COT-2026-0001')
            ->assertJsonPath('data.version_label', 'V2');

        // El listado también expone el número asignado.
        $listed = collect($this->getJson('/api/v1/quotes')->json('data'));
        $this->assertSame('COT-2026-0001', $listed->firstWhere('id', $first)['quote_number']);
        $this->assertSame('COT-2026-0002', $listed->firstWhere('id', $second)['quote_number']);
    }

    public function test_year_boundary_at_bogota_new_year_eve_stays_in_the_current_year(): void
    {
        // Precios válidos 15 días desde este ancla (2026-12-25), cubriendo el
        // cruce de año.
        $this->seedAt(Carbon::create(2026, 12, 25, 12, 0, 0, 'America/Bogota'));

        // 2026-12-31 23:30 en Bogotá (UTC-5) = 2027-01-01 04:30 UTC.
        $this->travelTo(now()->setTimezone('UTC')->setDate(2027, 1, 1)->setTime(4, 30, 0));

        $this->postJson('/api/v1/quotes', $this->payload())->assertCreated()
            ->assertJsonPath('data.quote_number', 'COT-2026-0001');

        // Un minuto después cruza a medianoche en Bogotá: nuevo año, nuevo contador.
        $this->travelTo(now()->setTimezone('UTC')->setDate(2027, 1, 1)->setTime(5, 1, 0));
        $this->postJson('/api/v1/quotes', $this->payload())->assertCreated()
            ->assertJsonPath('data.quote_number', 'COT-2027-0001');
    }

    public function test_a_failed_save_because_of_an_invalid_clause_reference_does_not_consume_a_number(): void
    {
        $this->seedAt(now()->setDate(2026, 9, 18)->startOfDay());

        $this->postJson('/api/v1/quotes', $this->payload([
            'clause_versions' => ['payment' => '00000000-0000-4000-8000-000000009999'],
        ]))->assertUnprocessable();

        $this->assertDatabaseMissing('quote_number_sequences', ['year' => 2026]);

        $this->postJson('/api/v1/quotes', $this->payload())->assertCreated()
            ->assertJsonPath('data.quote_number', 'COT-2026-0001');
    }

    public function test_unique_index_on_quote_number_and_revision_number_rejects_duplicates(): void
    {
        $this->seedAt(now()->setDate(2026, 9, 18)->startOfDay());
        $id = $this->postJson('/api/v1/quotes', $this->payload())->assertCreated()->json('data.id');
        $quote = DB::table('quotes')->find($id);

        $this->expectException(QueryException::class);
        DB::table('quotes')->insert([
            'id' => '00000000-0000-4000-8000-000000009998',
            'quote_number' => $quote->quote_number,
            'revision_number' => $quote->revision_number,
            'client_id' => $quote->client_id, 'site_id' => $quote->site_id,
            'status' => 'draft', 'snapshot' => $quote->snapshot,
            'created_by' => $quote->created_by,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
