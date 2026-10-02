<?php

namespace Tests\Feature;

use App\Domain\Quotes\ApprovalValidation;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class FreeLineQuoteTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT = '00000000-0000-4000-8000-000000000001';

    private const PRICE = '00000000-0000-4000-8000-000000000004';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        DB::table('commercial_rules')->insert(['family' => 'cctv', 'minimum_margin_bps' => 3000, 'max_discount_bps' => 500, 'review_above_cents' => 100000000, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @return array<string, mixed> */
    private function free(array $override = []): array
    {
        return array_merge([
            'type' => 'free', 'description' => 'Kit cámara bala con DVR y mano de obra', 'unit' => 'servicio', 'quantity' => '8',
            'price' => '200000.00', 'cost' => '150000.00', 'tax_bps' => 1900, 'discount_bps' => 0, 'confirmed_new' => true,
        ], $override);
    }

    /** @return array<string, mixed> */
    private function payload(array $lines): array
    {
        return [
            'client_id' => self::CLIENT, 'site_id' => '00000000-0000-4000-8000-000000000002', 'lines' => $lines,
            'family' => 'cctv', 'scope' => 'Alcance.', 'exclusions' => 'Sin obra civil.', 'payment_terms' => 'Contado.',
            'warranty' => 'Por confirmar.', 'validity_terms' => '15 días.', 'validity_days' => 15,
        ];
    }

    private function quoter(): User
    {
        $user = User::factory()->create(['role' => 'quoter']);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_free_line_previews_and_saves_with_same_math_as_catalog(): void
    {
        $this->quoter();
        $catalog = $this->postJson('/api/v1/quotes/preview', ['lines' => [['price_version_id' => self::PRICE, 'quantity' => '8', 'discount_bps' => 0]]])->assertOk();
        $free = $this->postJson('/api/v1/quotes/preview', ['lines' => [$this->free(['price' => '200000.00'])]])->assertOk();
        $this->assertSame($catalog->json('data.totals.total'), $free->json('data.totals.total'));
        $this->assertSame('1904000.00', $free->json('data.totals.total'));
        $this->assertArrayNotHasKey('cost_cents', $free->json('data.lines.0'));

        $saved = $this->postJson('/api/v1/quotes', $this->payload([$this->free()]))->assertCreated();
        $this->assertSame('free', $saved->json('data.lines.0.line_type'));
        $this->assertTrue(Str::isUuid($saved->json('data.lines.0.free_line_id')));
        $this->assertNull($saved->json('data.lines.0.price_version_id'));
        $stored = json_decode(DB::table('quotes')->find($saved->json('data.id'))->snapshot, true);
        $this->assertSame(15000000, $stored['lines'][0]['cost_cents']);
        $this->assertSame('cctv', $stored['lines'][0]['family']);
    }

    public function test_free_line_validation_and_roles(): void
    {
        $this->quoter();
        foreach ([['price' => '10.123'], ['price' => '-5'], ['price' => '0'], ['cost' => '-1.00'], ['tax_bps' => 1000],
            ['unit' => 'caja'], ['description' => 'abc'], ['confirmed_new' => false], ['price' => '10000000.01']] as $bad) {
            $this->postJson('/api/v1/quotes', $this->payload([$this->free($bad)]))->assertUnprocessable();
        }
        $this->postJson('/api/v1/quotes', $this->payload([$this->free(['free_line_id' => (string) Str::uuid()])]))->assertUnprocessable();
        $this->postJson('/api/v1/quotes', $this->payload(array_fill(0, 21, $this->free())))->assertUnprocessable();

        Sanctum::actingAs(User::factory()->create(['role' => 'approver']));
        $this->postJson('/api/v1/quotes', $this->payload([$this->free()]))->assertForbidden();
    }

    public function test_duplicate_of_active_item_is_rejected_with_sku(): void
    {
        $this->quoter();
        $this->postJson('/api/v1/quotes', $this->payload([$this->free(['description' => '  Cámara IP — ejemplo sin validez comercial '])]))
            ->assertUnprocessable()->assertJsonValidationErrors('lines.0.description')
            ->assertJsonFragment(['lines.0.description' => ['Ya existe el ítem SKU DEMO-CAM-IP activo con esa descripción; selecciónalo.']]);
        $this->assertSame(0, DB::table('quotes')->count());
    }

    public function test_emission_mode_requires_link_and_unchanged_price(): void
    {
        $this->quoter();
        $id = $this->postJson('/api/v1/quotes', $this->payload([$this->free()]))->assertCreated()->json('data.id');
        $record = DB::table('quotes')->find($id);
        $snapshot = json_decode($record->snapshot, true);
        $validation = $this->app->make(ApprovalValidation::class);

        $approval = DB::transaction(fn () => $validation->check($snapshot, $record, false));
        $this->assertStringContainsString('línea libre: al aprobar se crea ítem activo', $approval['flags'][0]);

        $this->assertThrows(fn () => DB::transaction(fn () => $validation->check($snapshot, $record, false, ApprovalValidation::MODE_EMISSION)), ValidationException::class);

        $itemId = (string) Str::uuid();
        $priceId = (string) Str::uuid();
        $ts = ['created_at' => now(), 'updated_at' => now()];
        DB::table('catalog_items')->insert(array_merge($ts, ['id' => $itemId, 'sku' => 'CCTV-LABCDEF12', 'description' => $snapshot['lines'][0]['description'], 'family' => 'cctv', 'unit' => 'servicio', 'active' => true]));
        DB::table('price_versions')->insert(array_merge($ts, ['id' => $priceId, 'catalog_item_id' => $itemId, 'version' => 1, 'price_cents' => 20000000, 'cost_cents' => 15000000, 'tax_bps' => 1900, 'valid_from' => now()->toDateString(), 'valid_until' => now()->addDays(30)->toDateString(), 'status' => 'approved']));
        DB::table('quote_free_line_items')->insert(['id' => (string) Str::uuid(), 'quote_id' => $id, 'free_line_id' => $snapshot['lines'][0]['free_line_id'], 'catalog_item_id' => $itemId, 'price_version_id' => $priceId, 'approved_by' => User::factory()->create()->id, 'created_at' => now()]);

        $result = DB::transaction(fn () => $validation->check($snapshot, $record, false, ApprovalValidation::MODE_EMISSION));
        $this->assertSame([], array_values(array_filter($result['flags'], fn ($f) => str_contains($f, 'línea libre') || str_contains($f, 'duplicado'))));

        DB::table('price_versions')->where('id', $priceId)->update(['status' => 'historical']);
        $this->assertThrows(fn () => DB::transaction(fn () => $validation->check($snapshot, $record, false, ApprovalValidation::MODE_EMISSION)), ValidationException::class);
        DB::table('price_versions')->where('id', $priceId)->update(['status' => 'approved', 'price_cents' => 21000000]);
        $this->assertThrows(fn () => DB::transaction(fn () => $validation->check($snapshot, $record, false, ApprovalValidation::MODE_EMISSION)), ValidationException::class);
    }

    public function test_legacy_snapshot_without_line_type_still_validates(): void
    {
        $this->quoter();
        $id = $this->postJson('/api/v1/quotes', $this->payload([['price_version_id' => self::PRICE, 'quantity' => '8', 'discount_bps' => 0]]))->assertCreated()->json('data.id');
        $record = DB::table('quotes')->find($id);
        $snapshot = json_decode($record->snapshot, true);
        $this->assertArrayNotHasKey('line_type', $snapshot['lines'][0]);
        $result = DB::transaction(fn () => $this->app->make(ApprovalValidation::class)->check($snapshot, $record, false, ApprovalValidation::MODE_EMISSION));
        $this->assertSame([], array_values(array_filter($result['flags'], fn ($f) => str_contains($f, 'línea libre') || str_contains($f, 'duplicado'))));
    }

    public function test_link_table_migration_is_reversible(): void
    {
        $this->assertTrue(Schema::hasTable('quote_free_line_items'));
        $migration = require database_path('migrations/2026_10_02_000001_create_quote_free_line_items.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('quote_free_line_items'));
        $migration->up();
        $this->assertTrue(Schema::hasTable('quote_free_line_items'));
    }
}
