<?php

namespace Tests\Feature;

use App\Domain\Quotes\ClauseText;
use App\Models\Clause;
use App\Models\ClauseVersion;
use App\Models\Company;
use App\Models\CompanyVersion;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Excepción del dueño del producto: las cotizaciones de un administrador quedan auto-aprobadas (no emitidas). */
final class AdminAutoApprovalTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $quoter;

    private User $approver;

    /** @var array<string, string>|null */
    private ?array $clauseCache = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->quoter = User::factory()->create(['role' => 'quoter']);
        $this->approver = User::factory()->create(['role' => 'approver']);
        DB::table('commercial_rules')->insert(['family' => 'cctv', 'minimum_margin_bps' => 3000, 'max_discount_bps' => 500, 'review_above_cents' => 100000000, 'created_at' => now(), 'updated_at' => now()]);
        $company = Company::query()->firstOrCreate(['code' => 'issuer'], ['id' => (string) Str::uuid()]);
        CompanyVersion::factory()->state([
            'company_id' => $company->id, 'version' => 1, 'nit' => '9017041071', 'emission_requires_authorization' => false, 'signer_name' => 'Firmante Ficticio',
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

    /** @param  array<string, mixed>  $line */
    private function payload(array $line = ['price_version_id' => '00000000-0000-4000-8000-000000000004', 'quantity' => '8', 'discount_bps' => 0]): array
    {
        return [
            'client_id' => '00000000-0000-4000-8000-000000000001', 'site_id' => '00000000-0000-4000-8000-000000000002',
            'lines' => [$line], 'family' => 'cctv', 'scope' => 'Alcance.', 'exclusions' => 'Sin obra civil.', 'payment_terms' => 'Contado.',
            'warranty' => 'Por confirmar.', 'validity_terms' => 'Vigencia de 15 días calendario.', 'validity_days' => 15,
            'clause_versions' => $this->clauses(),
        ];
    }

    /** @return array<string, mixed> */
    private function freeLine(): array
    {
        return ['type' => 'free', 'description' => 'Kit cámara bala con DVR y mano de obra', 'unit' => 'servicio', 'quantity' => '8',
            'price' => '200000.00', 'cost' => '150000.00', 'tax_bps' => 1900, 'discount_bps' => 0, 'confirmed_new' => true];
    }

    public function test_admin_saves_catalog_quote_approved_and_it_can_be_issued(): void
    {
        Sanctum::actingAs($this->admin);
        $response = $this->postJson('/api/v1/quotes', $this->payload())->assertCreated()
            ->assertJsonPath('data.status', 'approved')->assertJsonPath('data.created_items', []);
        $id = $response->json('data.id');
        $this->assertSame('approved', DB::table('quotes')->find($id)->status);
        $review = DB::table('quote_reviews')->where('quote_id', $id)->first();
        $this->assertSame('approve', $review->decision);
        $this->assertTrue((bool) $review->auto_approved);
        $this->assertSame($this->admin->id, (int) $review->user_id);
        $log = DB::table('audit_logs')->where('action', 'quote.auto_approved')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString($id, (string) json_encode($log));
        $this->getJson("/api/v1/quotes/{$id}")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->postJson("/api/v1/quotes/{$id}/issue", ['reason' => 'Emisión de prueba.'])->assertCreated()->assertJsonPath('data.status', 'issued');
    }

    public function test_admin_quote_without_site_is_auto_approved_and_issued(): void
    {
        Sanctum::actingAs($this->admin);
        $id = $this->postJson('/api/v1/quotes', array_merge($this->payload(), ['site_id' => null]))->assertCreated()
            ->assertJsonPath('data.status', 'approved')->assertJsonPath('data.site_name', null)->json('data.id');
        $this->postJson("/api/v1/quotes/{$id}/issue", ['reason' => 'Emisión de prueba.'])->assertCreated();
    }

    public function test_admin_free_line_creates_item_price_link_and_audit_without_confirm_new_items(): void
    {
        Sanctum::actingAs($this->admin);
        $response = $this->postJson('/api/v1/quotes', $this->payload($this->freeLine()))->assertCreated()->assertJsonPath('data.status', 'approved');
        $id = $response->json('data.id');
        $created = $response->json('data.created_items.0');
        $this->assertSame($response->json('data.lines.0.free_line_id'), $created['free_line_id']);
        $this->assertSame(1, (int) DB::table('catalog_items')->find($created['catalog_item_id'])->active);
        $price = DB::table('price_versions')->find($created['price_version_id']);
        $this->assertSame(1, (int) $price->version);
        $this->assertSame('approved', $price->status);
        $this->assertDatabaseHas('quote_free_line_items', ['quote_id' => $id, 'catalog_item_id' => $created['catalog_item_id'], 'approved_by' => $this->admin->id]);
        foreach (['catalog.created_from_quote', 'price.published'] as $action) {
            $log = DB::table('audit_logs')->where('action', $action)->latest('id')->first();
            $this->assertNotNull($log);
            $this->assertStringContainsString('admin_auto_approval', (string) json_encode($log));
            $this->assertStringNotContainsString('cost', (string) json_encode($log));
        }
        $this->postJson("/api/v1/quotes/{$id}/issue", ['reason' => 'Emisión de prueba.'])->assertCreated();
    }

    public function test_free_line_still_requires_confirmed_new_for_admin(): void
    {
        Sanctum::actingAs($this->admin);
        $line = $this->freeLine();
        unset($line['confirmed_new']);
        $this->postJson('/api/v1/quotes', $this->payload($line))->assertUnprocessable()->assertJsonValidationErrors('lines.0.confirmed_new');
    }

    public function test_blocking_validation_saves_nothing(): void
    {
        DB::table('commercial_rules')->delete();
        Sanctum::actingAs($this->admin);
        $quotes = DB::table('quotes')->count();
        $items = DB::table('catalog_items')->count();
        $this->postJson('/api/v1/quotes', $this->payload($this->freeLine()))->assertUnprocessable()->assertJsonValidationErrors('rules');
        $this->assertSame($quotes, DB::table('quotes')->count());
        $this->assertSame($items, DB::table('catalog_items')->count());
        $this->assertSame(0, DB::table('quote_reviews')->count());
    }

    public function test_admin_revision_is_approved_and_snapshot_is_untouched(): void
    {
        Sanctum::actingAs($this->quoter);
        $source = $this->postJson('/api/v1/quotes', $this->payload())->assertCreated()->json('data.id');
        Sanctum::actingAs($this->admin);
        $before = DB::table('quotes')->find($source)->snapshot;
        $revision = $this->postJson("/api/v1/quotes/{$source}/revisions", $this->payload())->assertCreated()->assertJsonPath('data.status', 'approved')->json('data.id');
        $this->assertSame('approved', DB::table('quotes')->find($revision)->status);
        $this->assertSame('draft', DB::table('quotes')->find($source)->status);
        $this->assertSame($before, DB::table('quotes')->find($source)->snapshot);
        $this->assertSame('draft', json_decode(DB::table('quotes')->find($revision)->snapshot, true)['status']);
    }

    public function test_quoter_never_auto_approves_even_when_forcing_fields(): void
    {
        Sanctum::actingAs($this->quoter);
        $response = $this->postJson('/api/v1/quotes', $this->payload() + ['auto_approved' => true, 'status' => 'approved', 'role' => 'admin'])->assertCreated();
        $id = $response->json('data.id');
        $this->assertSame('draft', $response->json('data.status'));
        $this->assertSame('draft', DB::table('quotes')->find($id)->status);
        $this->assertSame(0, DB::table('quote_reviews')->count());
        $this->postJson("/api/v1/quotes/{$id}/issue", ['reason' => 'Emisión de prueba.'])->assertStatus(409);
    }

    public function test_approver_still_cannot_save_and_admin_cannot_self_approve_in_review_flow_for_quoter_quotes(): void
    {
        Sanctum::actingAs($this->approver);
        $this->postJson('/api/v1/quotes', $this->payload())->assertForbidden();
        $this->postJson('/api/v1/quotes/preview', ['lines' => $this->payload()['lines']])->assertForbidden();
    }

    public function test_quoter_cannot_review_own_quote_unchanged(): void
    {
        Sanctum::actingAs($this->quoter);
        $id = $this->postJson('/api/v1/quotes', $this->payload())->assertCreated()->json('data.id');
        $this->postJson("/api/v1/quotes/{$id}/submit", ['reason' => 'Lista para revisión.'])->assertOk();
        $this->postJson("/api/v1/quotes/{$id}/review", ['decision' => 'approve', 'reason' => 'Aprobada para pruebas.'])->assertForbidden();
    }

    public function test_auto_approved_quote_with_required_authorization_needs_another_admin_or_approver(): void
    {
        DB::table('company_versions')->update(['emission_requires_authorization' => true]);
        Sanctum::actingAs($this->admin);
        $id = $this->postJson('/api/v1/quotes', $this->payload())->assertCreated()->json('data.id');
        $show = $this->getJson("/api/v1/quotes/{$id}")->assertOk();
        $this->assertTrue((bool) $show->json('data.reviews.0.auto_approved'));
        $this->assertStringContainsString('autorización', implode(' ', $show->json('data.issue_blockers')));
        $this->postJson("/api/v1/quotes/{$id}/issue", ['reason' => 'Emisión de prueba.'])->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->postJson("/api/v1/quotes/{$id}/issue", ['reason' => 'Emisión de prueba.'])->assertCreated()->assertJsonPath('data.status', 'issued');

        Sanctum::actingAs($this->admin);
        $other = $this->postJson('/api/v1/quotes', $this->payload())->assertCreated()->json('data.id');
        Sanctum::actingAs($this->approver);
        $this->postJson("/api/v1/quotes/{$other}/issue", ['reason' => 'Emisión de prueba.'])->assertCreated()->assertJsonPath('data.status', 'issued');
    }

    /**
     * Dos creaciones secuenciales con la misma descripción libre no duplican el ítem (la segunda se rechaza porque
     * la primera ya creó el ítem activo). La concurrencia real no es reproducible en un solo proceso; el advisory
     * lock por familia (solo pgsql) la serializa y en SQLite es no-op sin romper el flujo.
     */
    public function test_sequential_free_line_creations_do_not_duplicate_the_item(): void
    {
        Sanctum::actingAs($this->admin);
        $before = DB::table('catalog_items')->count();
        $this->postJson('/api/v1/quotes', $this->payload($this->freeLine()))->assertCreated();
        $this->postJson('/api/v1/quotes', $this->payload($this->freeLine()))->assertUnprocessable()->assertJsonValidationErrors('lines.0.description');
        $this->assertSame($before + 1, DB::table('catalog_items')->count());
        $this->assertSame(1, DB::table('catalog_items')->where('description', 'Kit cámara bala con DVR y mano de obra')->count());
    }
}
