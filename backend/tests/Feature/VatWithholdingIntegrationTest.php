<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class VatWithholdingIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT_ID = '00000000-0000-4000-8000-000000000001';

    private const SITE_ID = '00000000-0000-4000-8000-000000000002';

    private const PRICE_ID = '00000000-0000-4000-8000-000000000004';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
    }

    private function asQuoter(): User
    {
        $quoter = User::factory()->create(['role' => 'quoter', 'active' => true]);
        Sanctum::actingAs($quoter);

        return $quoter;
    }

    private function payload(): array
    {
        return [
            'client_id' => self::CLIENT_ID, 'site_id' => self::SITE_ID,
            'lines' => [['price_version_id' => self::PRICE_ID, 'quantity' => '8', 'discount_bps' => 0]],
            'family' => 'cctv',
            'scope' => 'Ocho cámaras.', 'exclusions' => 'Sin obra civil.', 'payment_terms' => 'Contado.', 'warranty' => 'Por confirmar.',
            'validity_terms' => 'Vigencia de 15 días calendario.', 'validity_days' => 15,
        ];
    }

    public function test_preview_without_client_has_no_withholding(): void
    {
        $this->asQuoter();
        $this->postJson('/api/v1/quotes/preview', ['lines' => $this->payload()['lines']])->assertOk()
            ->assertJsonPath('data.vat_withholding.applied', false)
            ->assertJsonPath('data.totals.vat_withholding', '0.00')
            ->assertJsonPath('data.totals.payable', '1904000.00');
    }

    public function test_preview_with_a_withholding_client_computes_vat_withholding(): void
    {
        DB::table('clients')->where('id', self::CLIENT_ID)->update(['withholds_vat' => true]);
        $this->asQuoter();
        $this->postJson('/api/v1/quotes/preview', ['lines' => $this->payload()['lines'], 'client_id' => self::CLIENT_ID])
            ->assertOk()->assertJsonPath('data.vat_withholding.applied', true)
            ->assertJsonPath('data.vat_withholding.rate_bps', 1500)
            ->assertJsonPath('data.totals.vat_withholding', '45600.00')
            ->assertJsonPath('data.totals.payable', '1858400.00');
    }

    public function test_creating_a_quote_for_a_withholding_client_stores_the_rate_and_totals(): void
    {
        DB::table('clients')->where('id', self::CLIENT_ID)->update(['withholds_vat' => true]);
        $this->asQuoter();
        $this->postJson('/api/v1/quotes', $this->payload())->assertCreated()
            ->assertJsonPath('data.vat_withholding.applied', true)
            ->assertJsonPath('data.vat_withholding.rate_bps', 1500)
            ->assertJsonPath('data.totals.vat_withholding', '45600.00')
            ->assertJsonPath('data.totals.payable', '1858400.00');
    }

    public function test_non_withholding_client_has_zero_withholding_and_payable_equals_total(): void
    {
        $this->asQuoter();
        $this->postJson('/api/v1/quotes', $this->payload())->assertCreated()
            ->assertJsonPath('data.vat_withholding.applied', false)
            ->assertJsonPath('data.totals.vat_withholding', '0.00')
            ->assertJsonPath('data.totals.payable', '1904000.00');
    }

    public function test_legacy_snapshot_without_vat_withholding_normalizes_to_zero_on_read(): void
    {
        $this->asQuoter();
        $id = $this->postJson('/api/v1/quotes', $this->payload())->assertCreated()->json('data.id');
        $snapshot = json_decode(DB::table('quotes')->find($id)->snapshot, true);
        unset($snapshot['vat_withholding'], $snapshot['totals']['vat_withholding'], $snapshot['totals']['payable'], $snapshot['family']);
        DB::table('quotes')->where('id', $id)->update(['snapshot' => json_encode($snapshot)]);

        $this->getJson('/api/v1/quotes/'.$id)->assertOk()
            ->assertJsonPath('data.vat_withholding.applied', false)
            ->assertJsonPath('data.totals.vat_withholding', '0.00')
            ->assertJsonPath('data.totals.payable', $snapshot['totals']['total']);
    }

    public function test_an_invalid_configured_rate_fails_visibly_instead_of_silently_miscalculating(): void
    {
        config(['quotes.vat_withholding_bps' => 20000]);
        DB::table('clients')->where('id', self::CLIENT_ID)->update(['withholds_vat' => true]);
        $this->asQuoter();

        $this->postJson('/api/v1/quotes/preview', ['lines' => $this->payload()['lines'], 'client_id' => self::CLIENT_ID])
            ->assertServerError();
        $this->postJson('/api/v1/quotes', $this->payload())->assertServerError();
        $this->assertDatabaseCount('quotes', 0);
    }

    public function test_approval_is_blocked_when_client_withholding_status_changed_since_saving(): void
    {
        $author = $this->asQuoter();
        $id = $this->postJson('/api/v1/quotes', $this->payload())->assertCreated()->json('data.id');
        $this->postJson("/api/v1/quotes/{$id}/submit", ['reason' => 'Enviar a revisión'])->assertOk();

        // El cliente pasa a ser agente retenedor después de guardar la cotización.
        DB::table('clients')->where('id', self::CLIENT_ID)->update(['withholds_vat' => true]);

        Sanctum::actingAs(User::factory()->create(['role' => 'approver', 'active' => true]));
        $this->postJson("/api/v1/quotes/{$id}/review", ['decision' => 'approve', 'reason' => 'Intentar aprobar con ReteIVA cambiada.'])
            ->assertUnprocessable();
    }
}
