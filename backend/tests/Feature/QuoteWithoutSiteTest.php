<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** La sede es opcional; el cliente sigue siendo obligatorio. */
final class QuoteWithoutSiteTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT = '00000000-0000-4000-8000-000000000001';

    private const SITE = '00000000-0000-4000-8000-000000000002';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
    }

    /** @return array<string, mixed> */
    private function payload(?string $site = null): array
    {
        $payload = [
            'client_id' => self::CLIENT,
            'lines' => [['price_version_id' => '00000000-0000-4000-8000-000000000004', 'quantity' => '8', 'discount_bps' => 0]],
            'family' => 'cctv', 'scope' => 'Ocho cámaras.', 'exclusions' => 'Sin obra civil.', 'payment_terms' => 'Contado.',
            'warranty' => 'Por confirmar.', 'validity_terms' => 'Vigencia de 15 días calendario.', 'validity_days' => 15,
        ];

        return $site === null ? $payload : $payload + ['site_id' => $site];
    }

    private function user(string $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    public function test_preview_without_site_and_with_null_site(): void
    {
        Sanctum::actingAs($this->user('quoter'));
        $this->postJson('/api/v1/quotes/preview', $this->payload())->assertOk();
        $this->postJson('/api/v1/quotes/preview', $this->payload() + ['site_id' => null])->assertOk();
        $this->postJson('/api/v1/quotes/preview', $this->payload((string) Str::uuid()))->assertUnprocessable()->assertJsonValidationErrors('site_id');
    }

    public function test_store_without_site_keeps_null_snapshot_and_appears_everywhere(): void
    {
        $quoter = $this->user('quoter');
        Sanctum::actingAs($quoter);
        $data = $this->postJson('/api/v1/quotes', $this->payload() + ['site_id' => null])->assertCreated()->assertJsonPath('data.site_id', null)->assertJsonPath('data.site_name', null)->json('data');
        $this->assertNull(DB::table('quotes')->find($data['id'])->site_id);
        $this->getJson('/api/v1/quotes')->assertOk()->assertJsonFragment(['id' => $data['id']]);
        $this->getJson('/api/v1/quotes/'.$data['id'])->assertOk()->assertJsonPath('data.site_name', null);
        $this->getJson('/api/v1/dashboard')->assertOk();
        $this->get("/api/v1/quotes/{$data['id']}/pdf")->assertOk();
    }

    public function test_foreign_site_still_fails_and_site_snapshot_is_unchanged(): void
    {
        Sanctum::actingAs($this->user('quoter'));
        $otherClient = (string) Str::uuid();
        DB::table('clients')->insert(['id' => $otherClient, 'name' => 'Otro cliente', 'created_at' => now(), 'updated_at' => now()]);
        $foreign = (string) Str::uuid();
        DB::table('sites')->insert(['id' => $foreign, 'client_id' => $otherClient, 'name' => 'Sede ajena', 'city' => 'Cali', 'created_at' => now(), 'updated_at' => now()]);
        $this->postJson('/api/v1/quotes', $this->payload($foreign))->assertUnprocessable()->assertJsonValidationErrors('site_id');
        $this->postJson('/api/v1/quotes', $this->payload(self::SITE))->assertCreated()
            ->assertJsonPath('data.site_id', self::SITE)->assertJsonPath('data.site_name', DB::table('sites')->find(self::SITE)->name);
    }

    public function test_revise_without_site(): void
    {
        Sanctum::actingAs($this->user('quoter'));
        $first = $this->postJson('/api/v1/quotes', $this->payload(self::SITE))->assertCreated()->json('data.id');
        $this->postJson("/api/v1/quotes/{$first}/revisions", $this->payload())->assertCreated()->assertJsonPath('data.site_name', null);
    }

    public function test_draft_pdf_without_site_has_no_null_text(): void
    {
        Sanctum::actingAs($this->user('quoter'));
        $id = $this->postJson('/api/v1/quotes', $this->payload())->assertCreated()->json('data.id');
        $site = 'sin-llamar';
        View::composer('quotes.draft-pdf', function ($view) use (&$site): void {
            $site = $view->getData()['quote']['site_name'];
        });
        $this->get("/api/v1/quotes/{$id}/pdf")->assertOk();
        $this->assertNull($site);
    }
}
