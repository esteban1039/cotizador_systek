<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        // The UTC date is already the 20th, while Bogotá is still the 19th.
        $this->travelTo(Carbon::parse('2026-09-20 02:00:00', 'UTC'));
    }

    private function quote(User $owner, string $status = 'draft', string $validUntil = '2026-09-19', int $age = 0): string
    {
        $id = (string) Str::uuid();
        DB::table('quotes')->insert([
            'id' => $id, 'client_id' => '00000000-0000-4000-8000-000000000001',
            'site_id' => '00000000-0000-4000-8000-000000000002', 'created_by' => $owner->id,
            'status' => $status, 'revision_number' => 1,
            'snapshot' => json_encode(['client_name' => 'Cliente histórico', 'scope' => 'Instalación CCTV', 'valid_until' => $validUntil, 'totals' => ['cost' => '123.00', 'total' => '456.00']]),
            'created_at' => now()->subMinutes($age), 'updated_at' => now(),
        ]);

        return $id;
    }

    public function test_dashboard_requires_an_active_authenticated_user(): void
    {
        $this->getJson('/api/v1/dashboard')->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create(['active' => false]));
        $this->getJson('/api/v1/dashboard')->assertForbidden();
    }

    public function test_unknown_role_cannot_access_dashboard(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'legacy']));
        $this->getJson('/api/v1/dashboard')->assertForbidden();
    }

    public function test_empty_dashboard_has_zero_counts(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/dashboard')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertExactJson(['data' => [
            'scope' => 'own', 'as_of' => '2026-09-19',
            'counts' => ['total' => 0, 'draft' => 0, 'in_review' => 0, 'approved' => 0, 'expired' => 0],
            'pending_quotes' => [],
        ]]);
    }

    public function test_quoter_counts_and_pending_quotes_exclude_other_owners(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $id = $this->quote($owner);
        $this->quote($owner, 'in_review', '2026-09-18', 1);
        $this->quote($owner, 'approved', '2026-09-18', 2);
        $hidden = $this->quote($other, 'draft', '2026-09-18');
        Sanctum::actingAs($owner);
        $response = $this->getJson('/api/v1/dashboard')->assertOk()
            ->assertJsonPath('data.scope', 'own')->assertJsonPath('data.as_of', '2026-09-19')
            ->assertJsonPath('data.counts', ['total' => 3, 'draft' => 1, 'in_review' => 1, 'approved' => 1, 'expired' => 2])
            ->assertJsonCount(2, 'data.pending_quotes')->assertJsonPath('data.pending_quotes.0.id', $id)
            ->assertJsonPath('data.pending_quotes.0.is_expired', false)
            ->assertJsonPath('data.pending_quotes.1.is_expired', true)
            ->assertJsonPath('data.pending_quotes.0.client_name', 'Cliente histórico');
        $this->assertStringNotContainsString($hidden, $response->getContent());
        $this->assertSame(['id', 'quote_number', 'revision_number', 'client_name', 'scope', 'status', 'created_at', 'valid_until', 'is_expired'], array_keys($response->json('data.pending_quotes.0')));
        $this->assertDatabaseHas('quotes', ['id' => $id, 'status' => 'draft']);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_admin_and_approver_see_all_quotes_and_only_five_recent_pending_items(): void
    {
        $owner = User::factory()->create();
        $newest = $this->quote($owner);
        for ($age = 1; $age < 7; $age++) {
            $this->quote($owner, 'in_review', '2026-09-21', $age);
        }
        $this->quote($owner, 'approved');
        foreach (['admin', 'approver'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));
            $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.scope', 'all')
                ->assertJsonPath('data.counts.total', 8)->assertJsonPath('data.counts.in_review', 6)
                ->assertJsonCount(5, 'data.pending_quotes')->assertJsonPath('data.pending_quotes.0.id', $newest);
        }
    }
}
