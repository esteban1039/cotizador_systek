<?php

namespace Tests\Feature;

use App\Domain\Quotes\ClauseText;
use App\Models\Clause;
use App\Models\ClauseVersion;
use App\Models\User;
use App\Repositories\Contracts\CatalogRepository;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class FreeLineApprovalTest extends TestCase
{
    use RefreshDatabase;

    private User $quoter;

    private User $approver;

    /** @var array<string, string>|null */
    private ?array $clauseCache = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        DB::table('commercial_rules')->insert(['family' => 'cctv', 'minimum_margin_bps' => 3000, 'max_discount_bps' => 500, 'review_above_cents' => 100000000, 'created_at' => now(), 'updated_at' => now()]);
        $this->quoter = User::factory()->create(['role' => 'quoter']);
        $this->approver = User::factory()->create(['role' => 'approver']);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'client_id' => '00000000-0000-4000-8000-000000000001', 'site_id' => '00000000-0000-4000-8000-000000000002',
            'lines' => [['type' => 'free', 'description' => 'Kit cámara bala con DVR y mano de obra', 'unit' => 'servicio', 'quantity' => '8',
                'price' => '200000.00', 'cost' => '150000.00', 'tax_bps' => 1900, 'discount_bps' => 0, 'confirmed_new' => true]],
            'family' => 'cctv', 'scope' => 'Alcance.', 'exclusions' => 'Sin obra civil.', 'payment_terms' => 'Contado.',
            'warranty' => 'Por confirmar.', 'validity_terms' => 'Vigencia de 15 días calendario.', 'validity_days' => 15,
            'clause_versions' => $this->clauses(),
        ];
    }

    /** @return array<string, string> */
    private function clauses(): array
    {
        if ($this->clauseCache !== null) {
            return $this->clauseCache;
        }
        $make = function (string $type, string $title, string $body): string {
            $clause = Clause::factory()->create(['family' => 'cctv', 'type' => $type, 'title' => $title, 'is_default' => true]);

            return ClauseVersion::factory()->create(['clause_id' => $clause->id, 'body' => $body, 'body_hash' => ClauseText::hash($body)])->id;
        };

        return $this->clauseCache = [
            'payment' => $make('payment', 'Contado', 'Contado.'),
            'warranty' => $make('warranty', 'Garantía estándar', 'Por confirmar.'),
            'validity' => $make('validity', 'Vigencia estándar', 'Vigencia de 15 días calendario.'),
        ];
    }

    /** @return array{0: string, 1: string} id y free_line_id */
    private function inReview(): array
    {
        Sanctum::actingAs($this->quoter);
        $saved = $this->postJson('/api/v1/quotes', $this->payload())->assertCreated();
        $id = $saved->json('data.id');
        $this->postJson("/api/v1/quotes/{$id}/submit", ['reason' => 'Lista para revisión.'])->assertOk();

        return [$id, $saved->json('data.lines.0.free_line_id')];
    }

    public function test_approval_creates_item_price_link_and_audit_without_costs_and_keeps_snapshot(): void
    {
        [$id, $line] = $this->inReview();
        $before = DB::table('quotes')->find($id)->snapshot;
        Sanctum::actingAs($this->approver);
        $items = DB::table('catalog_items')->count();

        $response = $this->postJson("/api/v1/quotes/{$id}/review", ['decision' => 'approve', 'reason' => 'Aprobada.', 'confirm_new_items' => [$line]])->assertOk()
            ->assertJsonPath('data.status', 'approved');
        $created = $response->json('data.created_items.0');
        $this->assertSame($line, $created['free_line_id']);
        $this->assertSame($items + 1, DB::table('catalog_items')->count());
        $item = DB::table('catalog_items')->find($created['catalog_item_id']);
        $this->assertSame(1, (int) $item->active);
        $this->assertSame(0, (int) $item->is_demo);
        $price = DB::table('price_versions')->find($created['price_version_id']);
        $this->assertSame(1, (int) $price->version);
        $this->assertSame('approved', $price->status);
        $this->assertSame(20000000, (int) $price->price_cents);
        $this->assertSame(15000000, (int) $price->cost_cents);
        $this->assertSame(now()->addDays(90)->toDateString(), substr((string) $price->valid_until, 0, 10));
        $this->assertDatabaseHas('quote_free_line_items', ['quote_id' => $id, 'free_line_id' => $line, 'catalog_item_id' => $item->id, 'approved_by' => $this->approver->id]);
        foreach (['catalog.created_from_quote', 'price.published'] as $action) {
            $log = DB::table('audit_logs')->where('action', $action)->latest('id')->first();
            $this->assertNotNull($log);
            $this->assertStringNotContainsString('cost', (string) json_encode($log));
            $this->assertStringContainsString('quote_approval', (string) json_encode($log));
        }
        $this->assertSame($before, DB::table('quotes')->find($id)->snapshot);

        $entry = DB::table('quote_knowledge')->first();
        if ($entry !== null) {
            $this->assertStringContainsString($created['sku'], (string) $entry->lines);
        }
    }

    public function test_missing_or_different_confirmation_is_rejected_and_nothing_created(): void
    {
        [$id, $line] = $this->inReview();
        Sanctum::actingAs($this->approver);
        $items = DB::table('catalog_items')->count();
        foreach ([[], ['confirm_new_items' => []], ['confirm_new_items' => ['00000000-0000-4000-8000-0000000000aa']], ['confirm_new_items' => [$line, $line]]] as $extra) {
            $this->postJson("/api/v1/quotes/{$id}/review", ['decision' => 'approve', 'reason' => 'Aprobada.'] + $extra)
                ->assertUnprocessable();
        }
        $this->assertSame($items, DB::table('catalog_items')->count());
        $this->assertSame('in_review', DB::table('quotes')->find($id)->status);
        $this->assertSame(0, DB::table('quote_free_line_items')->count());
    }

    public function test_return_creates_nothing(): void
    {
        [$id] = $this->inReview();
        Sanctum::actingAs($this->approver);
        $items = DB::table('catalog_items')->count();
        $this->postJson("/api/v1/quotes/{$id}/review", ['decision' => 'return', 'reason' => 'Corrige el precio.'])->assertOk()->assertJsonPath('data.created_items', []);
        $this->assertSame($items, DB::table('catalog_items')->count());
        $this->assertSame('draft', DB::table('quotes')->find($id)->status);
    }

    public function test_roles_and_author_independence(): void
    {
        [$id, $line] = $this->inReview();
        $body = ['decision' => 'approve', 'reason' => 'Aprobada.', 'confirm_new_items' => [$line]];
        Sanctum::actingAs($this->quoter);
        $this->postJson("/api/v1/quotes/{$id}/review", $body)->assertForbidden();

        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);
        // Excepción de admin: su propia cotización queda auto-aprobada al guardarse (docs/decimosexta-iteracion.md).
        $own = $this->postJson('/api/v1/quotes', $this->payload())->assertCreated()->assertJsonPath('data.status', 'approved');
        $this->assertCount(1, $own->json('data.created_items'));
        $this->assertSame(1, DB::table('quote_free_line_items')->count());

        Sanctum::actingAs($this->approver);
        $this->postJson("/api/v1/quotes/{$id}/review", $body)->assertOk();
    }

    public function test_failure_creating_price_rolls_back_the_approval(): void
    {
        [$id, $line] = $this->inReview();
        $real = $this->app->make(CatalogRepository::class);
        $this->mock(CatalogRepository::class, function ($mock) use ($real): void {
            $mock->shouldReceive('skuExists')->andReturnUsing(fn ($sku) => $real->skuExists($sku));
            $mock->shouldReceive('createItem')->andReturnUsing(fn ($a) => $real->createItem($a));
            $mock->shouldReceive('activeExactMatch')->andReturnUsing(fn ($d, $f) => $real->activeExactMatch($d, $f));
            $mock->shouldReceive('lockFreeLineFamilies')->andReturnUsing(fn ($f) => $real->lockFreeLineFamilies($f));
            $mock->shouldReceive('publishPrice')->andThrow(new \RuntimeException('boom'));
        });
        Sanctum::actingAs($this->approver);
        $items = DB::table('catalog_items')->count();
        $this->withoutExceptionHandling();
        try {
            $this->postJson("/api/v1/quotes/{$id}/review", ['decision' => 'approve', 'reason' => 'Aprobada.', 'confirm_new_items' => [$line]]);
            $this->fail('Debía fallar.');
        } catch (\RuntimeException) {
        }
        $this->assertSame('in_review', DB::table('quotes')->find($id)->status);
        $this->assertSame($items, DB::table('catalog_items')->count());
        $this->assertSame(0, DB::table('quote_free_line_items')->count());
        $this->assertSame(0, DB::table('quote_reviews')->where('quote_id', $id)->where('decision', 'approve')->count());
    }

    public function test_show_hides_costs_from_quoter_except_unlinked_free_lines_and_exposes_link(): void
    {
        [$id, $line] = $this->inReview();
        Sanctum::actingAs($this->quoter);
        $shown = $this->getJson("/api/v1/quotes/{$id}")->assertOk();
        $this->assertSame(15000000, $shown->json('data.lines.0.cost_cents'));
        $this->assertNull($shown->json('data.totals.cost'));
        $this->assertNull($shown->json('data.profit'));
        $this->assertNull($shown->json('data.lines.0.amounts.cost'));
        $this->getJson("/api/v1/quotes/{$id}/pdf")->assertOk();

        Sanctum::actingAs($this->approver);
        $this->postJson("/api/v1/quotes/{$id}/review", ['decision' => 'approve', 'reason' => 'Aprobada.', 'confirm_new_items' => [$line]])->assertOk();
        Sanctum::actingAs($this->quoter);
        $shown = $this->getJson("/api/v1/quotes/{$id}")->assertOk();
        $this->assertArrayNotHasKey('cost_cents', $shown->json('data.lines.0'));
        $this->assertSame(['catalog_item_id', 'sku', 'price_version_id'], array_keys($shown->json('data.lines.0.linked_item')));
    }

    public function test_similar_items(): void
    {
        $this->getJson('/api/v1/catalog/similar?q=camara')->assertUnauthorized();
        Sanctum::actingAs($this->quoter);
        $this->getJson('/api/v1/catalog/similar?q=ca')->assertUnprocessable();
        $row = $this->getJson('/api/v1/catalog/similar?q=cámara&family=cctv')->assertOk()->json('data.0');
        $this->assertNotNull($row);
        $this->assertSame(['id', 'sku', 'description', 'unit', 'family', 'price_version_id', 'price', 'valid_until', 'score'], array_keys($row));
        $this->assertIsString($row['price']);
        DB::table('catalog_items')->where('id', $row['id'])->update(['active' => false]);
        $ids = array_column($this->getJson('/api/v1/catalog/similar?q=cámara')->json('data'), 'id');
        $this->assertNotContains($row['id'], $ids);
        Sanctum::actingAs($this->approver);
        $this->getJson('/api/v1/catalog/similar?q=camara')->assertOk();
    }

    public function test_similar_is_throttled(): void
    {
        Sanctum::actingAs($this->quoter);
        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/api/v1/catalog/similar?q=camara')->assertOk();
        }
        $this->getJson('/api/v1/catalog/similar?q=camara')->assertStatus(429);
    }
}
