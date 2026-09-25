<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class ClauseApiTest extends TestCase
{
    use RefreshDatabase;

    private function asAdmin(): User
    {
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'family' => 'cctv', 'type' => 'payment', 'title' => 'Contado',
            'body' => 'Pago de contado contra entrega a satisfacción.',
            'is_default' => true, 'reason' => 'Cláusula de prueba',
        ], $overrides);
    }

    public function test_create_publishes_version_one_and_is_audited(): void
    {
        $this->asAdmin();
        $response = $this->postJson('/api/v1/admin/clauses', $this->payload())->assertCreated()
            ->assertJsonPath('data.family', 'cctv')->assertJsonPath('data.type', 'payment')
            ->assertJsonPath('data.is_default', true)->assertJsonPath('data.current_version.version', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'clause.created']);
        $id = $response->json('data.id');
        $this->getJson('/api/v1/admin/clauses/'.$id)->assertOk()->assertJsonCount(1, 'data.versions');
    }

    public function test_duplicate_title_for_same_family_and_type_is_rejected(): void
    {
        $this->asAdmin();
        $this->postJson('/api/v1/admin/clauses', $this->payload())->assertCreated();
        $this->postJson('/api/v1/admin/clauses', $this->payload(['is_default' => false]))
            ->assertUnprocessable()->assertJsonValidationErrors('title');
    }

    public function test_invalid_family_or_type_are_rejected(): void
    {
        $this->asAdmin();
        $this->postJson('/api/v1/admin/clauses', $this->payload(['family' => 'not-a-family']))->assertUnprocessable();
        $this->postJson('/api/v1/admin/clauses', $this->payload(['type' => 'not-a-type']))->assertUnprocessable();
    }

    public function test_body_over_the_type_max_length_is_rejected(): void
    {
        $this->asAdmin();
        $this->postJson('/api/v1/admin/clauses', $this->payload(['body' => str_repeat('a', 1001)]))
            ->assertUnprocessable()->assertJsonValidationErrors('body');
    }

    public function test_body_with_bank_account_like_digits_is_rejected(): void
    {
        $this->asAdmin();
        $this->postJson('/api/v1/admin/clauses', $this->payload(['body' => 'Consignar a la cuenta 12345678.']))
            ->assertUnprocessable()->assertJsonValidationErrors('body');
    }

    public function test_publishing_a_new_version_archives_the_previous_one_and_unchanged_text_is_rejected(): void
    {
        $this->asAdmin();
        $id = $this->postJson('/api/v1/admin/clauses', $this->payload())->assertCreated()->json('data.id');

        $this->postJson("/api/v1/admin/clauses/{$id}/versions", ['body' => $this->payload()['body'], 'reason' => 'Sin cambios'])
            ->assertUnprocessable();

        $this->postJson("/api/v1/admin/clauses/{$id}/versions", ['body' => 'Pago de contado, nuevo texto.', 'reason' => 'Ajuste de redacción'])
            ->assertCreated()->assertJsonPath('data.version', 2);

        $detail = $this->getJson("/api/v1/admin/clauses/{$id}")->assertOk();
        $this->assertSame('historical', $detail->json('data.versions.1.status'));
        $this->assertSame('current', $detail->json('data.versions.0.status'));
    }

    public function test_only_one_default_per_family_and_type_and_deactivating_clears_default(): void
    {
        $this->asAdmin();
        $first = $this->postJson('/api/v1/admin/clauses', $this->payload(['title' => 'Contado']))
            ->assertCreated()->json('data.id');
        $second = $this->postJson('/api/v1/admin/clauses', $this->payload(['title' => 'Anticipo 50/50', 'is_default' => true]))
            ->assertCreated()->json('data.id');

        $this->assertFalse($this->getJson("/api/v1/admin/clauses/{$first}")->json('data.is_default'));
        $this->assertTrue($this->getJson("/api/v1/admin/clauses/{$second}")->json('data.is_default'));

        $this->patchJson("/api/v1/admin/clauses/{$second}", ['active' => false, 'reason' => 'Ya no se usa'])
            ->assertOk()->assertJsonPath('data.active', false)->assertJsonPath('data.is_default', false);
    }

    public function test_marking_an_inactive_clause_as_default_is_rejected(): void
    {
        $this->asAdmin();
        $id = $this->postJson('/api/v1/admin/clauses', $this->payload(['is_default' => false]))->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/admin/clauses/{$id}", ['active' => false, 'reason' => 'Desactivar'])->assertOk();
        $this->patchJson("/api/v1/admin/clauses/{$id}", ['is_default' => true, 'reason' => 'Intentar predeterminar'])
            ->assertUnprocessable();
    }

    public function test_current_endpoint_lists_only_active_current_clauses_for_the_family_and_requires_family(): void
    {
        $admin = $this->asAdmin();
        $this->postJson('/api/v1/admin/clauses', $this->payload(['title' => 'Contado']))->assertCreated();
        $inactiveId = $this->postJson('/api/v1/admin/clauses', $this->payload(['title' => 'Inactiva', 'is_default' => false]))
            ->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/admin/clauses/{$inactiveId}", ['active' => false, 'reason' => 'No se usa'])->assertOk();
        $this->postJson('/api/v1/admin/clauses', $this->payload(['family' => 'equipment', 'title' => 'Contado equipos', 'is_default' => false]))
            ->assertCreated();

        Sanctum::actingAs(User::factory()->create(['role' => 'quoter', 'active' => true]));
        $this->getJson('/api/v1/clauses')->assertUnprocessable();
        $this->getJson('/api/v1/clauses?family=cctv')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Contado');

        Sanctum::actingAs(User::factory()->create(['role' => 'approver', 'active' => true]));
        $this->getJson('/api/v1/clauses?family=cctv')->assertForbidden();
    }

    public function test_admin_routes_are_forbidden_for_quoter_and_approver(): void
    {
        $admin = $this->asAdmin();
        $id = $this->postJson('/api/v1/admin/clauses', $this->payload())->assertCreated()->json('data.id');

        foreach (['quoter', 'approver'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role, 'active' => true]));
            $this->getJson('/api/v1/admin/clauses')->assertForbidden();
            $this->postJson('/api/v1/admin/clauses', $this->payload(['title' => 'Otro título']))->assertForbidden();
            $this->getJson("/api/v1/admin/clauses/{$id}")->assertForbidden();
            $this->patchJson("/api/v1/admin/clauses/{$id}", ['active' => false, 'reason' => 'Intento no autorizado'])->assertForbidden();
            $this->postJson("/api/v1/admin/clauses/{$id}/versions", ['body' => 'Texto intentado por rol no autorizado.', 'reason' => 'Intento no autorizado'])
                ->assertForbidden();
        }

        // Ningún intento anterior modificó el estado real de la cláusula.
        Sanctum::actingAs($admin);
        $this->getJson("/api/v1/admin/clauses/{$id}")->assertOk()->assertJsonPath('data.active', true)
            ->assertJsonCount(1, 'data.versions');
    }

    public function test_admin_routes_require_authentication(): void
    {
        $this->getJson('/api/v1/admin/clauses')->assertUnauthorized();
        $this->postJson('/api/v1/admin/clauses', $this->payload())->assertUnauthorized();
    }

    public function test_publishing_a_clause_version_does_not_change_bytes_of_existing_quote_snapshots(): void
    {
        $this->asAdmin();
        $this->seed(DemoSeeder::class);
        $response = $this->postJson('/api/v1/admin/clauses', $this->payload());
        $response->assertCreated();
        $clauseId = $response->json('data.id');
        $versionId = $response->json('data.current_version.id');

        Sanctum::actingAs(User::factory()->create(['role' => 'quoter', 'active' => true]));
        $quoteId = $this->postJson('/api/v1/quotes', [
            'client_id' => '00000000-0000-4000-8000-000000000001', 'site_id' => '00000000-0000-4000-8000-000000000002',
            'lines' => [['price_version_id' => '00000000-0000-4000-8000-000000000004', 'quantity' => '8', 'discount_bps' => 0]],
            'family' => 'cctv',
            'scope' => 'Ocho cámaras.', 'exclusions' => 'Sin obra civil.',
            'payment_terms' => $this->payload()['body'], 'warranty' => 'Por confirmar.',
            'validity_terms' => 'Vigencia de 15 días calendario.', 'validity_days' => 15,
            'clause_versions' => ['payment' => $versionId],
        ])->assertCreated()->json('data.id');

        $before = DB::table('quotes')->find($quoteId)->snapshot;

        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'active' => true]));
        $this->postJson("/api/v1/admin/clauses/{$clauseId}/versions", ['body' => 'Nuevo texto de pago.', 'reason' => 'Ajuste'])
            ->assertCreated();

        $after = DB::table('quotes')->find($quoteId)->snapshot;
        $this->assertSame($before, $after);
    }
}
