<?php

namespace Tests\Feature;

use App\Models\QuoteAssistRequest;
use App\Models\QuoteKnowledge;
use App\Models\User;
use App\Repositories\Contracts\CatalogRepository;
use App\Repositories\Contracts\KnowledgeRepository;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * F2: recuperación de precedentes. El ranking de calidad (ts_rank_cd + pg_trgm) solo corre en PostgreSQL (systek_test);
 * en SQLite se prueba el respaldo por términos (§6.4).
 */
final class QuoteAssistKnowledgeTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://api.anthropic.com/v1/messages';

    private const TEXT = 'Necesito ocho cámaras IP para una bodega, sin obra civil.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        Http::preventStrayRequests();
        config(['ai_assistant.enabled' => true, 'ai_assistant.api_key' => 'sk-test-not-a-real-key']);
        Sanctum::actingAs(User::factory()->create(['role' => 'quoter']));
    }

    /** @param array<string, mixed> $override */
    private function fakeOk(array $override = []): void
    {
        $input = array_merge([
            'family' => 'cctv', 'scope' => 'Alcance.', 'exclusions' => null, 'missing_information' => [],
            'lines' => [['sku' => 'DEMO-CAM-IP', 'quantity' => '8']],
        ], $override);
        Http::fake([self::URL => Http::response([
            'model' => 'claude-haiku-4-5-20251001', 'stop_reason' => 'tool_use', 'usage' => ['input_tokens' => 900, 'output_tokens' => 70],
            'content' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'propose_quote_draft', 'input' => $input]],
        ])]);
    }

    /** @param array<string, mixed> $attributes */
    private function knowledge(array $attributes = []): QuoteKnowledge
    {
        return QuoteKnowledge::factory()->create($attributes + [
            'requirement_text' => 'Ocho camaras IP para bodega sin obra civil',
            'lines_text' => 'Camara IP exterior bodega',
            'lines' => [['sku' => 'DEMO-CAM-IP', 'description' => 'Camara IP exterior bodega', 'unit' => 'unidad', 'quantity' => '8.000', 'family' => 'cctv', 'reference_price_cents' => 98765400, 'currency' => 'USD']],
        ]);
    }

    public function test_flag_off_keeps_current_behavior(): void
    {
        $this->knowledge();
        $this->fakeOk();

        $response = $this->postJson('/api/v1/quotes/assist', ['text' => self::TEXT])->assertOk();

        $this->assertArrayNotHasKey('precedents_used', $response->json('data'));
        $this->assertSame(0, QuoteAssistRequest::query()->count());
        Http::assertSent(fn (Request $r): bool => ! str_contains($r->body(), 'precedentes') && ! str_contains($r->body(), 'precedent_ids'));
    }

    public function test_flag_on_sends_precedents_without_money_or_client_data_and_records_request(): void
    {
        config(['ai_assistant.knowledge.enabled' => true]);
        $entry = $this->knowledge();
        $this->fakeOk(['precedent_ids' => ['P1', 'P9', 'X1']]);

        $response = $this->postJson('/api/v1/quotes/assist', ['text' => self::TEXT])->assertOk();

        $used = $response->json('data.precedents_used');
        $this->assertCount(1, $used);
        $this->assertSame(['source', 'captured_at'], array_keys($used[0]));
        $this->assertSame('drive_import', $used[0]['source']);
        Http::assertSent(function (Request $r) use ($entry): bool {
            $body = $r->body();

            return str_contains($body, '<precedentes>') && str_contains($body, 'Camara IP exterior bodega') && str_contains($body, 'precedent_ids')
                && ! str_contains($body, 'reference_price') && ! str_contains($body, 'currency') && ! str_contains($body, '98765400')
                && ! str_contains($body, 'USD') && ! str_contains($body, 'COP') && ! str_contains($body, $entry->id) && ! str_contains($body, 'source_ref');
        });
        $row = QuoteAssistRequest::query()->firstOrFail();
        $this->assertSame([$entry->id], $row->knowledge_ids);
        $this->assertSame([['sku' => 'DEMO-CAM-IP', 'quantity' => '8.000']], $row->proposed_lines);
        $this->assertSame(1, $row->precedents_sent);
        $this->assertSame(900, $row->input_tokens);
        $this->assertStringNotContainsString('bodega', (string) json_encode($row->getAttributes()));
        $this->assertSame($row->id, $response->json('data.request_id'));
    }

    public function test_unknown_sku_is_still_discarded_with_flag_on(): void
    {
        config(['ai_assistant.knowledge.enabled' => true]);
        $this->knowledge();
        $this->fakeOk(['lines' => [['sku' => 'DEMO-CAM-IP', 'quantity' => '2'], ['sku' => 'NO-EXISTE', 'quantity' => '1']]]);

        $response = $this->postJson('/api/v1/quotes/assist', ['text' => self::TEXT])->assertOk();

        $this->assertSame(['DEMO-CAM-IP'], array_column($response->json('data.lines'), 'sku'));
        $this->assertContains('unknown_sku', array_column($response->json('data.warnings'), 'code'));
    }

    public function test_flag_on_without_matches_sends_no_precedent_block(): void
    {
        config(['ai_assistant.knowledge.enabled' => true]);
        $this->knowledge(['requirement_text' => 'Zzz qqq', 'lines_text' => 'Xxx', 'status' => 'needs_review']);
        $this->fakeOk();

        $this->postJson('/api/v1/quotes/assist', ['text' => self::TEXT])->assertOk()->assertJsonPath('data.precedents_used', []);

        Http::assertSent(fn (Request $r): bool => ! str_contains($r->body(), '<precedentes>'));
    }

    public function test_fallback_similar_ranks_by_terms_and_only_active(): void
    {
        $best = $this->knowledge(['source_ref' => 'A']);
        $this->knowledge(['source_ref' => 'B', 'requirement_text' => 'Servidor rack', 'lines_text' => 'Disco']);
        $this->knowledge(['source_ref' => 'C', 'status' => 'excluded']);

        $found = app(KnowledgeRepository::class)->similar(self::TEXT, 'cctv', 4);

        $this->assertSame([$best->id], array_column($found, 'id'));
        $this->assertSame([], app(KnowledgeRepository::class)->similar('de la', null, 4));
    }

    public function test_diversity_caps_drive_entries_and_ai_assisted_entries(): void
    {
        config(['ai_assistant.knowledge.max_ai_assisted' => 1]);
        $this->knowledge(['source' => 'approved_quote', 'source_ref' => 'X', 'trust' => '1.00', 'ai_assisted' => true]);
        $this->knowledge(['source' => 'approved_quote', 'source_ref' => 'Y', 'trust' => '1.00', 'ai_assisted' => true]);
        $this->knowledge(['source' => 'approved_quote', 'source_ref' => 'Z', 'trust' => '1.00']);
        foreach (['D1', 'D2', 'D3'] as $ref) {
            $this->knowledge(['source_ref' => $ref]);
        }

        $found = app(KnowledgeRepository::class)->similar(self::TEXT, 'cctv', 6);
        $sources = array_count_values(array_column($found, 'source'));

        $this->assertSame(2, $sources['approved_quote']);
        $this->assertSame(2, $sources['drive_import']);
        $this->assertSame(1, count(array_filter($found, fn (array $f): bool => $f['ai_assisted'])));
    }

    public function test_postgres_ranking_prefers_family_and_relevance(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('El ranking ts_rank_cd/pg_trgm requiere PostgreSQL (systek_test).');
        }
        $this->knowledge(['source_ref' => 'REL']);
        $this->knowledge(['source_ref' => 'OTRO', 'family' => 'energia', 'requirement_text' => 'UPS online 3kVA para servidor', 'lines_text' => 'UPS online', 'lines' => [['sku' => 'UPS-1', 'description' => 'UPS online', 'unit' => 'unidad', 'quantity' => '1.000', 'family' => 'energia']]]);

        $found = app(KnowledgeRepository::class)->similar(self::TEXT, 'cctv', 4);

        $this->assertNotEmpty($found);
        $this->assertSame('cctv', $found[0]['family']);
        $this->assertNotContains('energia', array_column($found, 'family'));
    }

    public function test_assistant_catalog_puts_priority_skus_first(): void
    {
        $catalog = app(CatalogRepository::class);
        $plain = array_column($catalog->assistantCatalog(now()->toDateString(), null, 50)['items'], 'sku');
        $last = end($plain);

        $prioritized = $catalog->assistantCatalog(now()->toDateString(), null, 50, [$last]);

        $this->assertSame($last, $prioritized['items'][0]['sku']);
        $this->assertCount(count($plain), $prioritized['items']);
    }

    public function test_eval_command_reports_zero_without_approved_quotes(): void
    {
        $this->artisan('systek:eval-knowledge')->expectsOutputToContain('"evaluated":0')->assertSuccessful();
    }
}
