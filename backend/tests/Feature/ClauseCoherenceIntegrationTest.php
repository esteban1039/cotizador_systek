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

final class ClauseCoherenceIntegrationTest extends TestCase
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

    private function clauseVersion(string $family, string $type, string $title, string $body, bool $active = true): array
    {
        $clause = Clause::factory()->create(['family' => $family, 'type' => $type, 'title' => $title, 'is_default' => true, 'active' => $active]);
        $version = ClauseVersion::factory()->create(['clause_id' => $clause->id, 'body' => $body, 'body_hash' => ClauseText::hash($body)]);

        return ['clause' => $clause, 'version' => $version];
    }

    private function payload(array $overrides = []): array
    {
        Sanctum::actingAs($this->author);

        return array_merge([
            'client_id' => '00000000-0000-4000-8000-000000000001', 'site_id' => '00000000-0000-4000-8000-000000000002',
            'lines' => [['price_version_id' => '00000000-0000-4000-8000-000000000004', 'quantity' => '8', 'discount_bps' => 0]],
            'family' => 'cctv',
            'scope' => 'Ocho cámaras.', 'exclusions' => 'Sin obra civil.', 'payment_terms' => 'Contado.', 'warranty' => 'Por confirmar.',
            'validity_terms' => 'Vigencia de 15 días calendario.', 'validity_days' => 15,
        ], $overrides);
    }

    public function test_missing_payment_warranty_or_validity_clause_warns_on_submit_and_blocks_on_approve(): void
    {
        Sanctum::actingAs($this->author);
        $id = $this->postJson('/api/v1/quotes', $this->payload())->assertCreated()->json('data.id');

        // Antes de enviar, el propio autor ya ve las banderas en el detalle.
        $preSubmit = $this->getJson('/api/v1/quotes/'.$id)->assertOk()->assertJsonPath('data.can_submit', true);
        $this->assertCount(3, $preSubmit->json('data.review_flags'));
        $this->assertSame([], $preSubmit->json('data.approval_errors'));

        $this->postJson("/api/v1/quotes/{$id}/submit", ['reason' => 'Enviar sin cláusulas formales.'])
            ->assertOk()->assertJsonPath('data.status', 'in_review');
        $validation = json_decode(DB::table('quote_reviews')->where('quote_id', $id)->value('validation'), true);
        $this->assertCount(3, $validation['flags']);
        $this->assertStringContainsString('Falta la cl', $validation['flags'][0]);

        Sanctum::actingAs($this->approver);
        // Antes de decidir, el aprobador ve los mismos problemas como errores bloqueantes.
        $preReview = $this->getJson('/api/v1/quotes/'.$id)->assertOk()->assertJsonPath('data.can_review', true);
        $this->assertNotEmpty($preReview->json('data.approval_errors'));

        $this->postJson("/api/v1/quotes/{$id}/review", ['decision' => 'approve', 'reason' => 'Intentar aprobar sin cláusulas.'])
            ->assertUnprocessable();
        $this->assertDatabaseHas('quotes', ['id' => $id, 'status' => 'in_review']);
    }

    public function test_referencing_a_clause_of_another_family_is_blocked_at_save_time(): void
    {
        $warranty = $this->clauseVersion('data_power', 'warranty', 'Garantía datos y potencia', 'Garantía de equipos de red.');
        Sanctum::actingAs($this->author);
        $this->postJson('/api/v1/quotes', $this->payload([
            'warranty' => 'Garantía de equipos de red.',
            'clause_versions' => ['warranty' => $warranty['version']->id],
        ]))->assertUnprocessable()->assertJsonValidationErrors('clause_versions.warranty');
    }

    public function test_a_historical_or_inactive_clause_version_is_blocked_at_save_time(): void
    {
        $historical = $this->clauseVersion('cctv', 'warranty', 'Garantía vieja', 'Texto histórico.');
        $historical['version']->update(['status' => 'historical']);

        Sanctum::actingAs($this->author);
        $this->postJson('/api/v1/quotes', $this->payload([
            'warranty' => 'Texto histórico.',
            'clause_versions' => ['warranty' => $historical['version']->id],
        ]))->assertUnprocessable()->assertJsonValidationErrors('clause_versions.warranty');
    }

    public function test_quote_without_family_from_before_this_iteration_is_blocked_on_submit(): void
    {
        Sanctum::actingAs($this->author);
        $id = $this->postJson('/api/v1/quotes', $this->payload())->assertCreated()->json('data.id');
        $snapshot = json_decode(DB::table('quotes')->find($id)->snapshot, true);
        unset($snapshot['family']);
        DB::table('quotes')->where('id', $id)->update(['snapshot' => json_encode($snapshot)]);

        $this->postJson("/api/v1/quotes/{$id}/submit", ['reason' => 'Cotización legado.'])->assertUnprocessable();
    }

    public function test_text_matching_a_clause_of_another_family_and_not_the_own_family_is_blocked(): void
    {
        // La misma garantía existe publicada solo bajo "data_power"; el cotizador
        // escribe el mismo texto a mano en una cotización "cctv".
        $this->clauseVersion('data_power', 'warranty', 'Garantía datos y potencia', 'Garantía de doce meses sobre defectos de fabricación.');

        Sanctum::actingAs($this->author);
        $this->postJson('/api/v1/quotes', $this->payload([
            'warranty' => 'Garantía de doce meses sobre defectos de fabricación.',
        ]))->assertCreated()->json('data.id');

        // El guardado no bloquea (no se referenció ninguna cláusula), pero el
        // envío a revisión sí, porque el texto coincide con otra familia.
        $id = $this->postJson('/api/v1/quotes', $this->payload([
            'warranty' => 'Garantía de doce meses sobre defectos de fabricación.',
        ]))->assertCreated()->json('data.id');
        $this->postJson("/api/v1/quotes/{$id}/submit", ['reason' => 'Enviar con texto copiado de otra familia.'])
            ->assertUnprocessable();
    }

    public function test_modified_text_and_different_family_line_only_warn(): void
    {
        $payment = $this->clauseVersion('cctv', 'payment', 'Contado', 'Contado.');
        Sanctum::actingAs($this->author);
        $id = $this->postJson('/api/v1/quotes', $this->payload([
            'payment_terms' => 'Contado, con condiciones especiales.',
            'clause_versions' => ['payment' => $payment['version']->id],
        ]))->assertCreated()->json('data.id');

        $this->postJson("/api/v1/quotes/{$id}/submit", ['reason' => 'Enviar con texto modificado.'])->assertOk();
        $validation = json_decode(DB::table('quote_reviews')->where('quote_id', $id)->value('validation'), true);
        $this->assertTrue($validation['clauses'][0]['modified']);
        $this->assertTrue((bool) array_filter($validation['flags'], fn ($flag) => str_contains($flag, 'modific')));
    }

    public function test_a_clause_republished_after_saving_blocks_approval(): void
    {
        $warranty = $this->clauseVersion('cctv', 'warranty', 'Garantía estándar', 'Garantía estándar del fabricante.');
        $payment = $this->clauseVersion('cctv', 'payment', 'Contado', 'Contado.');
        $validity = $this->clauseVersion('cctv', 'validity', 'Vigencia estándar', 'Vigencia de 15 días calendario.');

        Sanctum::actingAs($this->author);
        $id = $this->postJson('/api/v1/quotes', $this->payload([
            'payment_terms' => 'Contado.', 'warranty' => 'Garantía estándar del fabricante.',
            'validity_terms' => 'Vigencia de 15 días calendario.',
            'clause_versions' => [
                'payment' => $payment['version']->id, 'warranty' => $warranty['version']->id, 'validity' => $validity['version']->id,
            ],
        ]))->assertCreated()->json('data.id');
        $this->postJson("/api/v1/quotes/{$id}/submit", ['reason' => 'Enviar a revisión.'])->assertOk();

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->postJson('/api/v1/admin/clauses/'.$warranty['clause']->id.'/versions', [
            'body' => 'Garantía estándar del fabricante, actualizada.', 'reason' => 'Ajuste de redacción',
        ])->assertCreated();

        Sanctum::actingAs($this->approver);
        $this->postJson("/api/v1/quotes/{$id}/review", ['decision' => 'approve', 'reason' => 'Intentar aprobar con cláusula reemplazada.'])
            ->assertUnprocessable();
    }
}
