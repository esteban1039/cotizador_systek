<?php

namespace Tests\Feature;

use App\Application\Quotes\MarkKnowledgeIssued;
use App\Domain\Quotes\AssistDiff;
use App\Domain\Quotes\ClauseText;
use App\Models\Clause;
use App\Models\ClauseVersion;
use App\Models\Quote;
use App\Models\QuoteAssistRequest;
use App\Models\QuoteKnowledge;
use App\Models\User;
use App\Repositories\Contracts\KnowledgeRepository;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class KnowledgeCaptureTest extends TestCase
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
        DB::table('commercial_rules')->insert(['family' => 'cctv', 'minimum_margin_bps' => 3000, 'max_discount_bps' => 500, 'review_above_cents' => 100000000, 'created_at' => now(), 'updated_at' => now()]);
        $make = function (string $type, string $title, string $body): string {
            $clause = Clause::factory()->create(['family' => 'cctv', 'type' => $type, 'title' => $title, 'is_default' => true]);

            return ClauseVersion::factory()->create(['clause_id' => $clause->id, 'body' => $body, 'body_hash' => ClauseText::hash($body)])->id;
        };
        $this->clauses = [
            'payment' => $make('payment', 'Contado', 'Contado.'),
            'warranty' => $make('warranty', 'Garantía estándar', 'Por confirmar.'),
            'validity' => $make('validity', 'Vigencia estándar', 'Vigencia de 15 días calendario.'),
        ];
    }

    /** @var array<string, string> */
    private array $clauses = [];

    private function body(array $overrides = []): array
    {
        return array_merge([
            'client_id' => '00000000-0000-4000-8000-000000000001', 'site_id' => '00000000-0000-4000-8000-000000000002',
            'lines' => [['price_version_id' => '00000000-0000-4000-8000-000000000004', 'quantity' => '8', 'discount_bps' => 0]],
            'family' => 'cctv', 'scope' => 'Ocho cámaras.', 'exclusions' => 'Sin obra civil.', 'payment_terms' => 'Contado.',
            'warranty' => 'Por confirmar.', 'validity_terms' => 'Vigencia de 15 días calendario.', 'validity_days' => 15,
            'clause_versions' => $this->clauses,
        ], $overrides);
    }

    private function draft(array $overrides = [], ?string $revisionOf = null): string
    {
        Sanctum::actingAs($this->author);
        $url = $revisionOf ? "/api/v1/quotes/{$revisionOf}/revisions" : '/api/v1/quotes';

        return $this->postJson($url, $this->body($overrides))->assertCreated()->json('data.id');
    }

    private function review(string $id, string $decision): void
    {
        Sanctum::actingAs($this->author);
        $this->postJson("/api/v1/quotes/{$id}/submit", ['reason' => 'Lista para revisión.'])->assertOk();
        Sanctum::actingAs($this->approver);
        $this->postJson("/api/v1/quotes/{$id}/review", ['decision' => $decision, 'reason' => 'Decisión de prueba.'])->assertOk();
    }

    private function assistRequest(User $user, array $extra = []): string
    {
        $id = (string) Str::uuid();
        QuoteAssistRequest::query()->create(array_merge([
            'id' => $id, 'user_id' => $user->id, 'family' => 'cctv', 'knowledge_ids' => [],
            'proposed_lines' => [['sku' => 'x', 'quantity' => '8']], 'created_at' => now(),
        ], $extra));

        return $id;
    }

    public function test_approving_creates_one_entry_without_sensitive_data(): void
    {
        $id = $this->draft();
        $this->review($id, 'approve');
        $entry = QuoteKnowledge::query()->sole();
        $this->assertSame($id, $entry->source_root_quote_id);
        $this->assertContains($entry->status, ['active', 'needs_review']);
        $this->assertFalse((bool) $entry->issued);
        $line = $entry->lines[0];
        $this->assertSame('COP', $line['currency']);
        $this->assertIsInt($line['reference_price_cents']);
        $this->assertEqualsCanonicalizing(['sku', 'description', 'unit', 'quantity', 'family', 'reference_price_cents', 'currency'], array_keys($line));
        $dump = json_encode($entry->getAttributes());
        $number = $this->getJson("/api/v1/quotes/{$id}")->json('data.quote_number');
        $this->assertStringNotContainsString((string) $number, (string) $dump);
        foreach (['cost', 'discount', 'tax', 'total', 'client_name', 'quote_number'] as $word) {
            $this->assertStringNotContainsString($word, (string) $dump);
        }
    }

    public function test_return_to_draft_does_not_capture(): void
    {
        $this->review($this->draft(), 'return');
        $this->assertSame(0, QuoteKnowledge::query()->count());
    }

    public function test_reapproving_does_not_duplicate_and_revision_replaces(): void
    {
        $id = $this->draft();
        $this->review($id, 'approve');
        $this->assertSame(1, QuoteKnowledge::query()->count());
        $rev = $this->draft(['scope' => 'Dieciséis cámaras.', 'lines' => [['price_version_id' => '00000000-0000-4000-8000-000000000004', 'quantity' => '16', 'discount_bps' => 0]]], $id);
        $this->review($rev, 'approve');
        $entry = QuoteKnowledge::query()->sole();
        $this->assertSame(2, (int) $entry->source_revision);
        $this->assertSame('16.000', $entry->lines[0]['quantity']);
    }

    public function test_issuing_marks_entry_issued(): void
    {
        $id = $this->draft();
        $this->review($id, 'approve');
        $this->app->make(MarkKnowledgeIssued::class)->execute($id);
        $entry = QuoteKnowledge::query()->sole();
        $this->assertTrue((bool) $entry->issued);
        $this->assertSame('1.10', (string) $entry->trust);
    }

    public function test_capture_failure_keeps_approval_and_audits(): void
    {
        $this->mock(KnowledgeRepository::class, function ($mock): void {
            $mock->shouldReceive('approvedQuote')->andThrow(new \RuntimeException('boom'));
        });
        $id = $this->draft();
        $this->review($id, 'approve');
        Sanctum::actingAs($this->approver);
        $this->getJson("/api/v1/quotes/{$id}")->assertJsonPath('data.status', 'approved');
        $this->assertDatabaseHas('audit_logs', ['action' => 'knowledge.capture_failed', 'subject_id' => $id]);
        $this->assertSame(0, QuoteKnowledge::query()->count());
    }

    public function test_assist_request_links_only_for_owner_and_records_outcome(): void
    {
        $mine = $this->assistRequest($this->author);
        $other = $this->assistRequest($this->approver);
        $old = $this->assistRequest($this->author, ['created_at' => now()->subHours(30)]);
        $id = $this->draft(['assist_request_id' => $mine]);
        $this->assertSame($id, QuoteAssistRequest::query()->find($mine)->root_quote_id);
        $this->draft(['assist_request_id' => $other]);
        $this->draft(['assist_request_id' => $old]);
        $this->draft(['assist_request_id' => 'no-es-uuid']);
        $this->assertNull(QuoteAssistRequest::query()->find($other)->root_quote_id);
        $this->assertNull(QuoteAssistRequest::query()->find($old)->root_quote_id);

        $this->review($id, 'approve');
        $request = QuoteAssistRequest::query()->find($mine);
        $this->assertNotNull($request->approved_at);
        $this->assertSame(1, $request->removed_lines);
        $this->assertTrue((bool) QuoteKnowledge::query()->where('source_root_quote_id', $id)->value('ai_assisted'));
        $this->assertStringNotContainsString('assist_request_id', (string) Quote::query()->find($id)->snapshot);
    }

    public function test_assist_diff(): void
    {
        $l = fn (string $s, string $q): array => ['sku' => $s, 'quantity' => $q];
        $same = AssistDiff::compare([$l('a', '2')], [$l('a', '2.000')]);
        $this->assertSame([1, 0, 0, 0, '0.000'], [$same['kept'], $same['qty_changed'], $same['removed'], $same['added'], $same['human_edit_ratio']]);
        $this->assertSame(1, AssistDiff::compare([$l('a', '2')], [$l('a', '3')])['qty_changed']);
        $this->assertSame(1, AssistDiff::compare([$l('a', '2')], [])['removed']);
        $added = AssistDiff::compare([$l('a', '2')], [$l('a', '2'), $l('b', '1')]);
        $this->assertSame([1, 1, '0.500'], [$added['kept'], $added['added'], $added['human_edit_ratio']]);
    }
}
