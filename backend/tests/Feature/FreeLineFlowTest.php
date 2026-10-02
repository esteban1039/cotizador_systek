<?php

namespace Tests\Feature;

use App\Application\Quotes\BackfillKnowledge;
use App\Application\Quotes\EvaluateKnowledge;
use App\Domain\Quotes\ClauseText;
use App\Models\Clause;
use App\Models\ClauseVersion;
use App\Models\Company;
use App\Models\CompanyVersion;
use App\Models\QuoteKnowledge;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class FreeLineFlowTest extends TestCase
{
    use RefreshDatabase;

    private const CATALOG_PRICE = '00000000-0000-4000-8000-000000000004';

    private User $quoter;

    private User $approver;

    private User $admin;

    /** @var array<string, string>|null */
    private ?array $clauseCache = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        DB::table('commercial_rules')->insert(['family' => 'cctv', 'minimum_margin_bps' => 3000, 'max_discount_bps' => 500, 'review_above_cents' => 100000000, 'created_at' => now(), 'updated_at' => now()]);
        $this->quoter = User::factory()->create(['role' => 'quoter']);
        $this->approver = User::factory()->create(['role' => 'approver']);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $company = Company::query()->firstOrCreate(['code' => 'issuer'], ['id' => (string) Str::uuid()]);
        CompanyVersion::query()->where('company_id', $company->id)->where('status', 'current')->update(['status' => 'historical']);
        CompanyVersion::factory()->state([
            'company_id' => $company->id, 'version' => 99, 'nit' => '9017041071', 'emission_requires_authorization' => false, 'signer_name' => 'Firmante Ficticio',
            'bank_account' => ['bank_name' => 'Banco Ficticio de Pruebas', 'account_type' => 'savings', 'account_number' => '5550001234567', 'account_holder' => null],
        ])->create();
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

    /** @return array<string, mixed> */
    private function free(string $description = 'Kit cámara bala con DVR y mano de obra'): array
    {
        return ['type' => 'free', 'description' => $description, 'unit' => 'servicio', 'quantity' => '8',
            'price' => '200000.00', 'cost' => '150000.00', 'tax_bps' => 1900, 'discount_bps' => 0, 'confirmed_new' => true];
    }

    /** @param  list<array<string, mixed>>|null  $lines
     * @return array<string, mixed> */
    private function payload(?array $lines = null): array
    {
        return [
            'client_id' => '00000000-0000-4000-8000-000000000001', 'site_id' => '00000000-0000-4000-8000-000000000002',
            'lines' => $lines ?? [$this->free()],
            'family' => 'cctv', 'scope' => 'Alcance.', 'exclusions' => 'Sin obra civil.', 'payment_terms' => 'Contado.',
            'warranty' => 'Por confirmar.', 'validity_terms' => 'Vigencia de 15 días calendario.', 'validity_days' => 15,
            'clause_versions' => $this->clauses(),
        ];
    }

    /** @return array{0: string, 1: list<string>} */
    private function submitted(?array $lines = null, ?User $by = null, ?string $revisionOf = null): array
    {
        Sanctum::actingAs($by ?? $this->quoter);
        $url = $revisionOf ? "/api/v1/quotes/{$revisionOf}/revisions" : '/api/v1/quotes';
        $saved = $this->postJson($url, $this->payload($lines))->assertCreated();
        $id = $saved->json('data.id');
        $this->postJson("/api/v1/quotes/{$id}/submit", ['reason' => 'Lista para revisión.'])->assertOk();
        $free = array_values(array_filter(array_column($saved->json('data.lines'), 'free_line_id')));

        return [$id, $free];
    }

    /** @param  list<string>  $confirm */
    private function approve(string $id, array $confirm)
    {
        Sanctum::actingAs($this->approver);

        return $this->postJson("/api/v1/quotes/{$id}/review", ['decision' => 'approve', 'reason' => 'Aprobada.', 'confirm_new_items' => $confirm]);
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private function approvedFree(?array $lines = null): array
    {
        [$id, $free] = $this->submitted($lines);
        $created = $this->approve($id, $free)->assertOk()->json('data.created_items.0');

        return [$id, $created];
    }

    private function issue(string $id)
    {
        Sanctum::actingAs($this->quoter);

        return $this->postJson("/api/v1/quotes/{$id}/issue", ['reason' => 'Emisión de prueba.']);
    }

    public function test_full_flow_save_submit_approve_issue(): void
    {
        [$id, $created] = $this->approvedFree();
        $this->issue($id)->assertCreated()->assertJsonPath('data.status', 'issued');
        $this->assertSame('issued', DB::table('quotes')->find($id)->status);
        $this->assertDatabaseHas('quote_emission_files', ['emission_id' => DB::table('quote_emissions')->where('quote_id', $id)->value('id')]);
        $this->assertNotEmpty($created['sku']);
    }

    public function test_issue_blocked_when_admin_publishes_v2_of_created_item(): void
    {
        [$id, $created] = $this->approvedFree();
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/v1/admin/catalog/{$created['catalog_item_id']}/prices", [
            'price' => '250000.00', 'cost' => '150000.00', 'tax_bps' => 1900, 'valid_until' => now()->addDays(30)->toDateString(), 'reason' => 'Nuevo precio.',
        ])->assertCreated();
        $this->issue($id)->assertUnprocessable()->assertJsonValidationErrors(['lines.0.price_version_id']);
        $this->assertSame('approved', DB::table('quotes')->find($id)->status);
    }

    public function test_issue_blocked_when_created_item_deactivated(): void
    {
        [$id, $created] = $this->approvedFree();
        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/v1/admin/catalog/{$created['catalog_item_id']}/active", ['active' => false])->assertOk();
        $this->issue($id)->assertUnprocessable();
        $this->assertSame('approved', DB::table('quotes')->find($id)->status);
    }

    public function test_approval_always_records_knowledge_with_created_sku_and_reference_price(): void
    {
        [, $created] = $this->approvedFree();
        $entry = QuoteKnowledge::query()->sole();
        $line = $entry->lines[0];
        $this->assertSame($created['sku'], $line['sku']);
        $this->assertSame(20000000, $line['reference_price_cents']);
        $this->assertStringNotContainsString('cost', (string) json_encode($entry->getAttributes()));
    }

    public function test_backfill_and_evaluate_resolve_linked_free_lines(): void
    {
        [, $created] = $this->approvedFree();
        QuoteKnowledge::query()->delete();
        $dry = $this->app->make(BackfillKnowledge::class)->handle(true);
        $this->assertSame(1, $dry['quotes']);
        $this->assertSame(0, QuoteKnowledge::query()->count());
        $this->app->make(BackfillKnowledge::class)->handle(false);
        $entry = QuoteKnowledge::query()->sole();
        $this->assertSame($created['sku'], $entry->lines[0]['sku']);
        $this->assertSame(20000000, $entry->lines[0]['reference_price_cents']);
        $again = $this->app->make(BackfillKnowledge::class)->handle(false);
        $this->assertSame(1, $again['unchanged']);
        $this->assertSame(1, QuoteKnowledge::query()->count());
        $eval = $this->app->make(EvaluateKnowledge::class)->handle(4);
        $this->assertSame(1, $eval['evaluated']);
    }

    public function test_auto_activate_false_leaves_entry_in_needs_review(): void
    {
        config(['ai_assistant.knowledge.auto_activate' => false]);
        $this->approvedFree();
        $this->assertSame('needs_review', QuoteKnowledge::query()->sole()->status);
    }

    public function test_auto_activate_true_activates_entry(): void
    {
        config(['ai_assistant.knowledge.auto_activate' => true]);
        $this->approvedFree();
        $this->assertSame('active', QuoteKnowledge::query()->sole()->status);
    }

    public function test_other_quoter_cannot_see_or_edit_and_confirm_ids_of_other_quote_are_rejected(): void
    {
        [$id, $free] = $this->submitted();
        [$other, $otherFree] = $this->submitted(null, $this->quoter, null);
        $intruder = User::factory()->create(['role' => 'quoter']);
        Sanctum::actingAs($intruder);
        $this->assertContains($this->getJson("/api/v1/quotes/{$id}")->status(), [403, 404]);
        $this->assertContains($this->postJson("/api/v1/quotes/{$id}/revisions", $this->payload())->status(), [403, 404]);
        $this->assertContains($this->postJson("/api/v1/quotes/{$id}/submit", ['reason' => 'Intento ajeno.'])->status(), [403, 404, 409, 422]);
        $this->assertNotContains($id, array_column($this->getJson('/api/v1/quotes')->json('data') ?? [], 'id'));

        $items = DB::table('catalog_items')->count();
        $this->approve($id, $otherFree)->assertUnprocessable();
        $this->approve($id, [...$free, ...$otherFree])->assertUnprocessable();
        $this->assertSame($items, DB::table('catalog_items')->count());
        $this->assertSame('in_review', DB::table('quotes')->find($id)->status);
    }

    public function test_reapproval_does_not_duplicate_items(): void
    {
        [$id, $free] = $this->submitted();
        $this->approve($id, $free)->assertOk();
        $items = DB::table('catalog_items')->count();
        $this->approve($id, $free)->assertStatus(409);
        $this->assertSame($items, DB::table('catalog_items')->count());
        $this->assertSame(1, DB::table('quote_free_line_items')->count());
    }

    public function test_same_free_description_in_two_quotes_gets_distinct_skus(): void
    {
        [$a, $freeA] = $this->submitted();
        [$b, $freeB] = $this->submitted();
        $first = $this->approve($a, $freeA)->assertOk()->json('data.created_items.0');
        $second = $this->approve($b, $freeB)->assertOk()->json('data.created_items.0');
        $this->assertNotSame($first['sku'], $second['sku']);
        $this->assertNotSame($first['catalog_item_id'], $second['catalog_item_id']);
        $this->assertSame(2, DB::table('quote_free_line_items')->count());
    }

    public function test_free_line_mixed_with_catalog_has_consistent_totals(): void
    {
        $lines = [['price_version_id' => self::CATALOG_PRICE, 'quantity' => '8', 'discount_bps' => 0], $this->free()];
        Sanctum::actingAs($this->quoter);
        $saved = $this->postJson('/api/v1/quotes', $this->payload($lines))->assertCreated();
        $freeLine = $saved->json('data.lines.1');
        $this->assertSame('1904000.00', $freeLine['amounts']['total'] ?? null);
        $sum = 0;
        foreach ($saved->json('data.lines') as $line) {
            $sum += (int) round(((float) $line['amounts']['total']) * 100);
        }
        $this->assertSame((int) round(((float) $saved->json('data.totals.total')) * 100), $sum);
        $this->assertNull($saved->json('data.lines.0.free_line_id'));
        $id = $saved->json('data.id');
        $this->postJson("/api/v1/quotes/{$id}/submit", ['reason' => 'Lista para revisión.'])->assertOk();
        $this->approve($id, [$freeLine['free_line_id']])->assertOk()->assertJsonCount(1, 'data.created_items');
        $this->issue($id)->assertCreated();
    }

    public function test_revision_of_approved_quote_with_linked_free_line_uses_catalog_line(): void
    {
        [$id, $created] = $this->approvedFree();
        Sanctum::actingAs($this->quoter);
        $shown = $this->getJson("/api/v1/quotes/{$id}")->assertOk();
        $this->assertSame($created['price_version_id'], $shown->json('data.lines.0.linked_item.price_version_id'));

        $lines = [['price_version_id' => $created['price_version_id'], 'quantity' => '10', 'discount_bps' => 0]];
        [$rev] = $this->submitted($lines, $this->quoter, $id);
        $this->approve($rev, [])->assertOk()->assertJsonPath('data.created_items', []);
        $entry = QuoteKnowledge::query()->sole();
        $this->assertSame(2, (int) $entry->source_revision);
        $this->assertSame($created['sku'], $entry->lines[0]['sku']);
        $this->assertSame('10.000', $entry->lines[0]['quantity']);
        $this->issue($rev)->assertCreated();
    }

    public function test_revision_resubmitting_same_free_line_is_rejected_as_duplicate_of_created_item(): void
    {
        [$id] = $this->approvedFree();
        Sanctum::actingAs($this->quoter);
        $this->postJson("/api/v1/quotes/{$id}/revisions", $this->payload())->assertUnprocessable()->assertJsonValidationErrors(['lines.0.description']);
    }
}
