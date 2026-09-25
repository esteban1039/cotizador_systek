<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class CompanyApiTest extends TestCase
{
    use RefreshDatabase;

    private function asAdmin(): User
    {
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        Sanctum::actingAs($admin);

        return $admin;
    }

    /** Cuenta bancaria completamente ficticia, generada aquí solo para pruebas. */
    private function fakeBankAccount(string $number = '1234567890'): array
    {
        return [
            'bank_name' => 'Banco Ficticio de Pruebas', 'account_type' => 'savings',
            'account_number' => $number, 'account_number_confirmation' => $number, 'account_holder' => 'Titular de prueba',
        ];
    }

    public function test_show_without_any_version_reports_all_fields_missing(): void
    {
        $this->asAdmin();
        $this->getJson('/api/v1/admin/company')->assertOk()
            ->assertJsonPath('data.configured', false)
            ->assertJsonPath('data.complete', false)
            ->assertJsonCount(8, 'data.missing')
            ->assertJsonPath('data.version', null)
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_publish_creates_version_one_and_partial_update_creates_version_two_archiving_the_first(): void
    {
        $this->asAdmin();
        $this->postJson('/api/v1/admin/company', [
            'legal_name' => 'Systek Company S.A.S.', 'nit' => '901704107-1',
            'address' => 'Cra 75 # 28-21, Belén, Medellín', 'phone' => '3045869886',
            'email' => 'stip@systekcompany.io', 'website' => 'https://systekcompany.io',
            'signer_name' => 'Jhonatan Stip Gutierrez', 'signer_title' => 'Gerente',
            'reason' => 'Carga inicial de prueba',
        ])->assertCreated()->assertJsonPath('data.version', 1)->assertJsonPath('data.bank_account_configured', false)
            ->assertJsonPath('data.missing', ['bank_account']);

        $response = $this->postJson('/api/v1/admin/company', [
            'legal_name' => 'Systek Company S.A.S.', 'nit' => '901704107-1',
            'phone' => '3000000000', 'reason' => 'Actualización de teléfono',
        ])->assertCreated()->assertJsonPath('data.version', 2)->assertJsonPath('data.phone', '3000000000');

        $this->assertSame(1, DB::table('company_versions')->where('status', 'historical')->count());
        $this->assertSame(2, (int) $response->json('data.version'));
    }

    public function test_bank_account_number_never_appears_in_full_and_is_masked_with_last_four_digits(): void
    {
        $this->asAdmin();
        $account = $this->fakeBankAccount('9876543210');
        $response = $this->postJson('/api/v1/admin/company', [
            'legal_name' => 'Systek Company S.A.S.', 'reason' => 'Configurar cuenta de prueba',
            'bank_account' => $account,
        ])->assertCreated();

        $response->assertJsonPath('data.bank_account_configured', true)
            ->assertJsonPath('data.bank_account_summary.account_number_masked', '••••3210')
            ->assertJsonPath('data.bank_account_summary.has_holder', true)
            ->assertJsonMissingPath('data.bank_account_summary.account_number')
            ->assertJsonMissingPath('data.bank_account');

        $this->assertStringNotContainsString('9876543210', $response->getContent());

        $stored = DB::table('company_versions')->where('status', 'current')->value('bank_account');
        $this->assertStringNotContainsString('9876543210', (string) $stored);

        $show = $this->getJson('/api/v1/admin/company')->assertOk();
        $this->assertStringNotContainsString('9876543210', $show->getContent());

        $auditContent = $this->getJson('/api/v1/audit')->getContent();
        $this->assertStringNotContainsString('9876543210', $auditContent);
        $this->assertStringContainsString('Datos bancarios actualizados', $auditContent);
    }

    public function test_bank_account_is_preserved_when_omitted_and_cleared_when_requested(): void
    {
        $this->asAdmin();
        $account = $this->fakeBankAccount('1111222233');
        $this->postJson('/api/v1/admin/company', [
            'legal_name' => 'Systek Company S.A.S.', 'reason' => 'Configurar cuenta', 'bank_account' => $account,
        ])->assertCreated();

        $this->postJson('/api/v1/admin/company', [
            'legal_name' => 'Systek Company S.A.S.', 'reason' => 'Actualizar solo la razón social',
        ])->assertCreated()->assertJsonPath('data.bank_account_configured', true)
            ->assertJsonPath('data.bank_account_summary.account_number_masked', '••••2233');

        $this->postJson('/api/v1/admin/company', [
            'legal_name' => 'Systek Company S.A.S.', 'reason' => 'Eliminar la cuenta', 'clear_bank_account' => true,
        ])->assertCreated()->assertJsonPath('data.bank_account_configured', false)
            ->assertJsonPath('data.bank_account_summary', null);
    }

    public function test_clear_bank_account_accepts_numeric_boolean_and_empty_bank_account_is_rejected(): void
    {
        $this->asAdmin();
        $this->postJson('/api/v1/admin/company', [
            'legal_name' => 'Systek Company S.A.S.', 'reason' => 'Configurar cuenta', 'bank_account' => $this->fakeBankAccount('1111222233'),
        ])->assertCreated();

        $this->postJson('/api/v1/admin/company', [
            'legal_name' => 'Systek Company S.A.S.', 'reason' => 'Cuenta vacía', 'bank_account' => [],
        ])->assertUnprocessable();

        $this->postJson('/api/v1/admin/company', [
            'legal_name' => 'Systek Company S.A.S.', 'reason' => 'Eliminar con uno', 'clear_bank_account' => 1,
        ])->assertCreated()->assertJsonPath('data.bank_account_configured', false);
    }

    public function test_bank_account_and_clear_together_are_rejected(): void
    {
        $this->asAdmin();
        $this->postJson('/api/v1/admin/company', [
            'legal_name' => 'Systek Company S.A.S.', 'reason' => 'Inconsistente',
            'bank_account' => $this->fakeBankAccount(), 'clear_bank_account' => true,
        ])->assertUnprocessable();
    }

    public function test_bank_account_confirmation_mismatch_is_rejected_without_echoing_the_value(): void
    {
        $this->asAdmin();
        $response = $this->postJson('/api/v1/admin/company', [
            'legal_name' => 'Systek Company S.A.S.', 'reason' => 'Confirmación distinta',
            'bank_account' => [
                'bank_name' => 'Banco Ficticio', 'account_type' => 'savings',
                'account_number' => '1234567890', 'account_number_confirmation' => '1234567899',
            ],
        ])->assertUnprocessable();
        $this->assertStringNotContainsString('1234567890', $response->getContent());
        $this->assertStringNotContainsString('1234567899', $response->getContent());
    }

    public function test_nit_check_digit_is_validated_and_dots_are_normalized(): void
    {
        $this->asAdmin();
        $this->postJson('/api/v1/admin/company', [
            'legal_name' => 'Systek Company S.A.S.', 'nit' => '901704107-2', 'reason' => 'NIT inválido',
        ])->assertUnprocessable()->assertJsonValidationErrors('nit');

        $this->postJson('/api/v1/admin/company', [
            'legal_name' => 'Systek Company S.A.S.', 'nit' => '901.704.107-1', 'reason' => 'NIT con puntos',
        ])->assertCreated()->assertJsonPath('data.nit', '901704107-1');
    }

    public function test_website_must_be_https(): void
    {
        $this->asAdmin();
        $this->postJson('/api/v1/admin/company', [
            'legal_name' => 'Systek Company S.A.S.', 'website' => 'http://systekcompany.io', 'reason' => 'Sitio no seguro',
        ])->assertUnprocessable()->assertJsonValidationErrors('website');
    }

    public function test_invalid_email_or_missing_reason_are_rejected(): void
    {
        $this->asAdmin();
        $this->postJson('/api/v1/admin/company', [
            'legal_name' => 'Systek Company S.A.S.', 'email' => 'not-an-email', 'reason' => 'Correo inválido',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson('/api/v1/admin/company', ['legal_name' => 'Systek Company S.A.S.'])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
    }

    public function test_quoter_and_approver_are_forbidden_on_both_routes(): void
    {
        foreach (['quoter', 'approver'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role, 'active' => true]));
            $this->getJson('/api/v1/admin/company')->assertForbidden();
            $this->postJson('/api/v1/admin/company', ['legal_name' => 'X', 'reason' => 'Intento no autorizado'])->assertForbidden();
        }
    }

    public function test_publish_route_is_throttled(): void
    {
        $this->asAdmin();
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/admin/company', ['legal_name' => 'Systek Company S.A.S.', 'reason' => 'Solicitud '.$i]);
        }
        $this->postJson('/api/v1/admin/company', ['legal_name' => 'Systek Company S.A.S.', 'reason' => 'Solicitud extra'])
            ->assertStatus(429);
    }
}
