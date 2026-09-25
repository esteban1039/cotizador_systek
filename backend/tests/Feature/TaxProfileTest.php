<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class TaxProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
    }

    private const CLIENT_ID = '00000000-0000-4000-8000-000000000001';

    public function test_admin_can_toggle_withholds_vat_and_it_is_audited(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'active' => true]));
        $this->patchJson('/api/v1/clients/'.self::CLIENT_ID.'/tax-profile', [
            'withholds_vat' => true, 'reason' => 'Resolución de agente retenedor verificada',
        ])->assertOk()->assertJsonPath('data.withholds_vat', true);
        $this->assertDatabaseHas('clients', ['id' => self::CLIENT_ID, 'withholds_vat' => true]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'client.tax_profile_changed']);

        $details = json_decode(DB::table('audit_logs')->where('action', 'client.tax_profile_changed')->value('details'), true);
        $this->assertFalse($details['previous']);
        $this->assertTrue($details['withholds_vat']);
        $this->assertSame('Resolución de agente retenedor verificada', $details['reason']);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->patchJson('/api/v1/clients/'.self::CLIENT_ID.'/tax-profile', [
            'withholds_vat' => true, 'reason' => 'Sin autenticación',
        ])->assertUnauthorized();
    }

    public function test_reason_is_required(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'active' => true]));
        $this->patchJson('/api/v1/clients/'.self::CLIENT_ID.'/tax-profile', ['withholds_vat' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
    }

    public function test_missing_client_returns_404(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'active' => true]));
        $this->patchJson('/api/v1/clients/00000000-0000-4000-8000-000000000099/tax-profile', [
            'withholds_vat' => true, 'reason' => 'Cliente inexistente',
        ])->assertNotFound();
    }

    public function test_quoter_and_approver_are_forbidden(): void
    {
        foreach (['quoter', 'approver'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role, 'active' => true]));
            $this->patchJson('/api/v1/clients/'.self::CLIENT_ID.'/tax-profile', [
                'withholds_vat' => true, 'reason' => 'Intento no autorizado',
            ])->assertForbidden();
        }
    }

    public function test_directory_exposes_withholds_vat(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'quoter', 'active' => true]));
        $value = $this->getJson('/api/v1/clients')->assertOk()->json('data.0.withholds_vat');
        $this->assertFalse((bool) $value);
    }
}
