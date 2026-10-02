<?php

namespace Tests\Feature;

use App\Models\QuoteAssistRequest;
use App\Models\QuoteKnowledge;
use App\Models\User;
use App\Repositories\Contracts\KnowledgeRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class AiKnowledgeAdminTest extends TestCase
{
    use RefreshDatabase;

    private function entry(array $over = []): QuoteKnowledge
    {
        return QuoteKnowledge::factory()->create($over + ['lines' => [['sku' => 'CAM-1', 'description' => 'Camara IP', 'unit' => 'unidad', 'quantity' => 2, 'family' => 'cctv', 'reference_price_cents' => 123456]]]);
    }

    /** @return list<array{string, string, array<string, mixed>}> */
    private function routes(string $id): array
    {
        return [['GET', '/api/v1/ai-knowledge', []], ['GET', '/api/v1/ai-knowledge/metrics', []], ['GET', '/api/v1/ai-knowledge/'.$id, []],
            ['PATCH', '/api/v1/ai-knowledge/'.$id, ['status' => 'excluded', 'reason' => 'Duplicada']]];
    }

    public function test_admin_succeeds_and_other_roles_and_guests_are_rejected(): void
    {
        $id = $this->entry()->id;
        foreach ($this->routes($id) as [$method, $uri, $body]) {
            $this->json($method, $uri, $body)->assertUnauthorized();
        }
        foreach (['quoter', 'approver'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));
            foreach ($this->routes($id) as [$method, $uri, $body]) {
                $this->json($method, $uri, $body)->assertForbidden();
            }
        }
        $this->assertSame('active', QuoteKnowledge::find($id)->status);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        foreach ($this->routes($id) as [$method, $uri, $body]) {
            $this->json($method, $uri, $body)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        }
    }

    public function test_admin_without_mfa_enrollment_is_forbidden(): void
    {
        config(['security.mfa_required_roles' => ['admin']]);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->getJson('/api/v1/ai-knowledge')->assertForbidden()->assertJsonPath('code', 'mfa_enrollment_required');
    }

    public function test_list_filters_and_detail_shows_decimal_price_without_float(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $a = $this->entry(['requirement_text' => 'Camaras para bodega']);
        $this->entry(['status' => 'needs_review', 'family' => 'redes', 'source' => 'approved_quote', 'source_ref' => null, 'requirement_text' => 'Switch']);
        $this->getJson('/api/v1/ai-knowledge')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/ai-knowledge?status=needs_review')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.family', 'redes');
        $this->getJson('/api/v1/ai-knowledge?q=bodega&source=drive_import')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/ai-knowledge?status=otro')->assertUnprocessable();
        $this->getJson('/api/v1/ai-knowledge/'.$a->id)->assertOk()->assertJsonPath('data.lines.0.reference_price', '1234.56')->assertJsonMissingPath('data.lines.0.reference_price_cents');
        $this->getJson('/api/v1/ai-knowledge/'.Str::uuid())->assertNotFound();
        $this->getJson('/api/v1/ai-knowledge/no-uuid')->assertNotFound();
    }

    public function test_patch_requires_reason_and_something_to_change(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $id = $this->entry()->id;
        $this->patchJson('/api/v1/ai-knowledge/'.$id, ['status' => 'excluded'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->patchJson('/api/v1/ai-knowledge/'.$id, ['reason' => 'Motivo'])->assertUnprocessable();
        $this->patchJson('/api/v1/ai-knowledge/'.$id, ['status' => 'needs_review', 'reason' => 'Motivo'])->assertUnprocessable();
    }

    public function test_excluding_removes_from_similar_and_activating_needs_review_makes_it_eligible(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);
        config(['ai_assistant.knowledge.min_score' => 0.1]);
        $repo = app(KnowledgeRepository::class);
        $active = $this->entry(['requirement_text' => 'Camara exterior bodega']);
        $this->assertCount(1, $repo->similar('camara exterior bodega', 'cctv', 4));
        $this->patchJson('/api/v1/ai-knowledge/'.$active->id, ['status' => 'excluded', 'reason' => 'Dato erroneo'])->assertOk()->assertJsonPath('data.status', 'excluded')->assertJsonPath('data.review_reason', 'Dato erroneo');
        $this->assertSame([], $repo->similar('camara exterior bodega', 'cctv', 4));
        $this->assertDatabaseHas('audit_logs', ['action' => 'knowledge.status_changed', 'user_id' => $admin->id]);

        $review = $this->entry(['status' => 'needs_review', 'requirement_text' => 'Camara interior pasillo', 'source_ref' => 'REF-X']);
        $this->assertSame([], $repo->similar('camara interior pasillo', 'cctv', 4));
        $this->patchJson('/api/v1/ai-knowledge/'.$review->id, ['status' => 'active', 'reason' => 'Revisada manualmente'])->assertOk();
        $this->assertCount(1, $repo->similar('camara interior pasillo', 'cctv', 4));
    }

    public function test_edit_rescrubs_texts_and_audit_never_contains_them(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);
        $id = $this->entry()->id;
        $this->patchJson('/api/v1/ai-knowledge/'.$id, ['requirement_text' => 'Camaras escribir a juan@cliente.com o llamar 3001234567', 'scope' => 'Instalar', 'reason' => 'Ajuste de texto'])
            ->assertOk()->assertJsonPath('data.scope', 'Instalar');
        $row = QuoteKnowledge::find($id);
        $this->assertStringNotContainsString('juan@cliente.com', $row->requirement_text);
        $this->assertStringNotContainsString('3001234567', $row->requirement_text);
        $this->assertContains('email_removed', $row->scrub_flags);
        $this->assertContains('phone_removed', $row->scrub_flags);
        $this->assertSame($admin->id, $row->reviewed_by);
        $log = DB::table('audit_logs')->where('action', 'knowledge.edited')->first();
        $this->assertNotNull($log);
        $this->assertStringNotContainsString('Camaras', json_encode($log));
        $this->assertStringNotContainsString('Instalar', json_encode($log));
    }

    public function test_metrics_aggregate_sample_data(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->entry();
        $this->entry(['status' => 'needs_review', 'source' => 'approved_quote', 'source_ref' => null, 'family' => 'redes']);
        $user = User::factory()->create();
        $base = ['user_id' => $user->id, 'knowledge_ids' => [], 'proposed_lines' => [], 'input_tokens' => 100, 'output_tokens' => 50, 'created_at' => now()];
        QuoteAssistRequest::create($base + ['id' => (string) Str::uuid(), 'precedents_sent' => 2, 'kept_lines' => 3, 'qty_changed_lines' => 1, 'removed_lines' => 0, 'added_lines' => 1, 'approved_at' => now()]);
        QuoteAssistRequest::create($base + ['id' => (string) Str::uuid(), 'precedents_sent' => 0, 'kept_lines' => 1, 'qty_changed_lines' => 0, 'removed_lines' => 3, 'added_lines' => 0, 'approved_at' => now()]);
        QuoteAssistRequest::create($base + ['id' => (string) Str::uuid(), 'precedents_sent' => 1]);

        $this->getJson('/api/v1/ai-knowledge/metrics')->assertOk()
            ->assertJsonPath('data.acceptance.rate', 0.5)->assertJsonPath('data.acceptance.requests', 2)
            ->assertJsonPath('data.acceptance_with_precedents.rate', 0.75)->assertJsonPath('data.acceptance_without_precedents.rate', 0.25)
            ->assertJsonPath('data.coverage.requests', 3)->assertJsonPath('data.coverage.with_precedents', 2)
            ->assertJsonPath('data.tokens.input', 300)->assertJsonPath('data.tokens.output', 150)
            ->assertJsonPath('data.entries.total', 2)->assertJsonPath('data.entries.needs_review', 1)
            ->assertJsonCount(2, 'data.by_family')->assertJsonCount(2, 'data.by_source');
    }
}
