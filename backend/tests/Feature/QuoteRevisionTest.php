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

final class QuoteRevisionTest extends TestCase
{
    use RefreshDatabase;

    private User $author;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->author = User::factory()->create(['role' => 'quoter']);
        Sanctum::actingAs($this->author);
    }

    private function input(): array
    {
        return [
            'client_id' => '00000000-0000-4000-8000-000000000001', 'site_id' => '00000000-0000-4000-8000-000000000002',
            'lines' => [['price_version_id' => '00000000-0000-4000-8000-000000000004', 'quantity' => '8', 'discount_bps' => 0]],
            'family' => 'cctv',
            'scope' => 'Ocho cámaras.', 'exclusions' => 'Sin obra civil.', 'payment_terms' => 'Contado.', 'warranty' => 'Por confirmar.',
            'validity_terms' => 'Vigencia de 15 días calendario.', 'validity_days' => 15,
        ];
    }

    private function original(): string
    {
        return $this->postJson('/api/v1/quotes', $this->input())->assertCreated()->assertJsonPath('data.revision_number', 1)->json('data.id');
    }

    public function test_revision_preserves_original_and_starts_draft_without_approval(): void
    {
        $id = $this->original();
        DB::table('quotes')->where('id', $id)->update(['status' => 'approved']);
        $before = DB::table('quotes')->find($id);
        $input = $this->input();
        $input['scope'] = 'Nuevo alcance.';
        $input['lines'][0]['quantity'] = '2';
        $new = $this->postJson('/api/v1/quotes/'.$id.'/revisions', $input)->assertCreated()
            ->assertJsonPath('data.status', 'draft')->assertJsonPath('data.emission_allowed', false)
            ->assertJsonPath('data.root_quote_id', $id)->assertJsonPath('data.previous_quote_id', $id)
            ->assertJsonPath('data.revision_number', 2)->assertJsonPath('data.totals.total', '476000.00')->json('data.id');
        $this->assertEquals($before, DB::table('quotes')->find($id));
        $this->assertDatabaseMissing('quote_reviews', ['quote_id' => $new]);
        $this->getJson('/api/v1/quotes/'.$new)->assertOk()->assertJsonPath('data.can_submit', true)
            ->assertJsonPath('data.can_revise', true)->assertJsonCount(0, 'data.reviews')->assertJsonCount(2, 'data.revisions');
        $this->assertDatabaseHas('audit_logs', ['action' => 'quote.revised', 'subject_id' => $new]);
    }

    public function test_revision_numbers_are_sequential_even_when_branching_from_original(): void
    {
        $id = $this->original();
        $second = $this->postJson('/api/v1/quotes/'.$id.'/revisions', $this->input())->assertCreated()->json('data.id');
        $this->postJson('/api/v1/quotes/'.$second.'/revisions', $this->input())->assertCreated()->assertJsonPath('data.revision_number', 3)->assertJsonPath('data.root_quote_id', $id)->assertJsonPath('data.previous_quote_id', $second);
        $this->postJson('/api/v1/quotes/'.$id.'/revisions', $this->input())->assertCreated()->assertJsonPath('data.revision_number', 4);
        $this->getJson('/api/v1/quotes/'.$id)->assertOk()->assertJsonCount(4, 'data.revisions');
    }

    public function test_revision_requires_ownership_and_approver_cannot_revise(): void
    {
        $id = $this->original();
        Sanctum::actingAs(User::factory()->create(['role' => 'quoter']));
        $this->postJson('/api/v1/quotes/'.$id.'/revisions', $this->input())->assertNotFound();
        Sanctum::actingAs(User::factory()->create(['role' => 'approver']));
        $this->postJson('/api/v1/quotes/'.$id.'/revisions', $this->input())->assertForbidden();
        $this->getJson('/api/v1/quotes/'.$id)->assertOk()->assertJsonPath('data.can_revise', false);
        $this->assertDatabaseCount('quotes', 1);
    }

    public function test_admin_revision_is_not_leaked_in_original_authors_history(): void
    {
        $id = $this->original();
        DB::table('commercial_rules')->insert(['family' => 'cctv', 'minimum_margin_bps' => 3000, 'max_discount_bps' => 500, 'review_above_cents' => 100000000, 'created_at' => now(), 'updated_at' => now()]);
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);
        $make = function (string $type, string $title, string $body): string {
            $clause = Clause::factory()->create(['family' => 'cctv', 'type' => $type, 'title' => $title, 'is_default' => true]);

            return ClauseVersion::factory()->create(['clause_id' => $clause->id, 'body' => $body, 'body_hash' => ClauseText::hash($body)])->id;
        };
        $input = $this->input() + ['clause_versions' => [
            'payment' => $make('payment', 'Contado', 'Contado.'), 'warranty' => $make('warranty', 'Garantía estándar', 'Por confirmar.'),
            'validity' => $make('validity', 'Vigencia estándar', 'Vigencia de 15 días calendario.'),
        ]];
        $new = $this->postJson('/api/v1/quotes/'.$id.'/revisions', $input)->assertCreated()->assertJsonPath('data.created_by', $admin->id)->json('data.id');
        Sanctum::actingAs($this->author);
        $this->getJson('/api/v1/quotes/'.$id)->assertOk()->assertJsonCount(1, 'data.revisions');
        $this->getJson('/api/v1/quotes/'.$new)->assertNotFound();
        $this->getJson('/api/v1/quotes')->assertJsonPath('total', 1);
    }

    public function test_stale_prices_are_not_carried_into_revision_and_fail_atomically(): void
    {
        $id = $this->original();
        DB::table('price_versions')->update(['status' => 'historical']);
        $this->postJson('/api/v1/quotes/'.$id.'/revisions', $this->input())->assertUnprocessable();
        $this->assertDatabaseCount('quotes', 1);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'quote.revised']);
    }
}
