<?php

namespace Tests\Feature;

use App\Domain\Quotes\ClauseText;
use App\Models\Clause;
use App\Models\ClauseVersion;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class QuoteReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $author;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->author = User::factory()->create(['role' => 'quoter']);
        $this->approver = User::factory()->create(['role' => 'approver']);
    }

    private function draft(array $overrides = []): string
    {
        Sanctum::actingAs($this->author);

        return $this->postJson('/api/v1/quotes', array_merge([
            'client_id' => '00000000-0000-4000-8000-000000000001', 'site_id' => '00000000-0000-4000-8000-000000000002',
            'lines' => [['price_version_id' => '00000000-0000-4000-8000-000000000004', 'quantity' => '8', 'discount_bps' => 0]],
            'family' => 'cctv',
            'scope' => 'Ocho cámaras.', 'exclusions' => 'Sin obra civil.', 'payment_terms' => 'Contado.', 'warranty' => 'Por confirmar.',
            'validity_terms' => 'Vigencia de 15 días calendario.', 'validity_days' => 15,
        ], $overrides))->assertCreated()->json('data.id');
    }

    /**
     * Cláusulas vigentes de pago/garantía/vigencia para la familia cctv, con
     * texto idéntico al de la instantánea (para no disparar advertencias de
     * texto modificado en la aprobación).
     */
    private function coherentClauseVersions(): array
    {
        $make = function (string $type, string $title, string $body): string {
            $clause = Clause::factory()->create(['family' => 'cctv', 'type' => $type, 'title' => $title, 'is_default' => true]);

            return ClauseVersion::factory()->create([
                'clause_id' => $clause->id, 'body' => $body, 'body_hash' => ClauseText::hash($body),
            ])->id;
        };

        return [
            'payment' => $make('payment', 'Contado', 'Contado.'),
            'warranty' => $make('warranty', 'Garantía estándar', 'Por confirmar.'),
            'validity' => $make('validity', 'Vigencia estándar', 'Vigencia de 15 días calendario.'),
        ];
    }

    private function rules(): void
    {
        DB::table('commercial_rules')->insert(['family' => 'cctv', 'minimum_margin_bps' => 3000, 'max_discount_bps' => 500, 'review_above_cents' => 100000000, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_separate_reviewer_can_approve_with_recorded_exceptions_but_cannot_emit(): void
    {
        $id = $this->draft(['clause_versions' => $this->coherentClauseVersions()]);
        $this->postJson("/api/v1/quotes/{$id}/submit", ['reason' => 'Lista para revisión.'])->assertOk();
        $this->rules();
        Sanctum::actingAs($this->approver);
        $this->postJson("/api/v1/quotes/{$id}/review", ['decision' => 'approve', 'reason' => 'Acepto las excepciones de margen y monto.'])
            ->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.emission_allowed', false)
            ->assertJsonCount(2, 'data.validation.flags');
        $this->getJson("/api/v1/quotes/{$id}")->assertJsonPath('data.status', 'approved')->assertJsonCount(2, 'data.reviews');
        $this->assertDatabaseCount('quote_reviews', 2);
    }

    public function test_missing_rules_block_approval(): void
    {
        $id = $this->draft();
        $this->postJson("/api/v1/quotes/{$id}/submit", ['reason' => 'Revisar propuesta.'])->assertOk();
        Sanctum::actingAs($this->approver);
        $this->postJson("/api/v1/quotes/{$id}/review", ['decision' => 'approve', 'reason' => 'Intentar aprobar.'])->assertUnprocessable();
        $this->assertDatabaseHas('quotes', ['id' => $id, 'status' => 'in_review']);
    }

    public function test_admin_quote_is_auto_approved_so_it_cannot_be_submitted_or_reviewed_again(): void
    {
        $this->rules();
        $this->author->role = 'admin';
        $this->author->save();
        $id = $this->draft(['clause_versions' => $this->coherentClauseVersions()]);
        $this->assertDatabaseHas('quotes', ['id' => $id, 'status' => 'approved']);
        $this->postJson("/api/v1/quotes/{$id}/submit", ['reason' => 'Revisar propuesta.'])->assertConflict();
        $this->postJson("/api/v1/quotes/{$id}/review", ['decision' => 'approve', 'reason' => 'Aprobación propia.'])->assertConflict();
    }

    public function test_quoter_duplicate_submission_is_rejected_and_own_approval_forbidden(): void
    {
        $id = $this->draft();
        $this->postJson("/api/v1/quotes/{$id}/submit", ['reason' => 'Revisar propuesta.'])->assertOk();
        $this->postJson("/api/v1/quotes/{$id}/submit", ['reason' => 'Revisar propuesta.'])->assertConflict();
        $this->postJson("/api/v1/quotes/{$id}/review", ['decision' => 'approve', 'reason' => 'Aprobación propia.'])->assertForbidden();
    }

    public function test_return_requires_reason_and_is_audited(): void
    {
        $id = $this->draft();
        $this->postJson("/api/v1/quotes/{$id}/submit", ['reason' => 'Revisar propuesta.'])->assertOk();
        Sanctum::actingAs($this->approver);
        $this->postJson("/api/v1/quotes/{$id}/review", ['decision' => 'return', 'reason' => ''])->assertUnprocessable();
        $this->postJson("/api/v1/quotes/{$id}/review", ['decision' => 'return', 'reason' => 'Corregir condiciones.'])->assertOk()->assertJsonPath('data.status', 'draft');
        $this->assertDatabaseHas('audit_logs', ['action' => 'quote.return', 'subject_id' => $id]);
    }

    public function test_expired_price_and_changed_totals_block_review(): void
    {
        $id = $this->draft();
        $this->postJson("/api/v1/quotes/{$id}/submit", ['reason' => 'Revisar propuesta.'])->assertOk();
        $this->rules();
        Sanctum::actingAs($this->approver);
        DB::table('price_versions')->update(['valid_until' => now()->subDay()->toDateString()]);
        $this->postJson("/api/v1/quotes/{$id}/review", ['decision' => 'approve', 'reason' => 'Revisar precio.'])->assertUnprocessable();
        DB::table('price_versions')->update(['valid_until' => now()->addDay()->toDateString(), 'price_cents' => 123]);
        $this->postJson("/api/v1/quotes/{$id}/review", ['decision' => 'approve', 'reason' => 'Revisar precio.'])->assertUnprocessable();
    }
}
