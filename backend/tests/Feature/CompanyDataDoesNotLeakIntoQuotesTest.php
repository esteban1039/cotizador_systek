<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Los datos bancarios (y el firmante) de la empresa emisora nunca deben
 * aparecer en `GET /quotes/{id}` ni en los datos de la vista del PDF, para
 * ningún rol: son datos de solo escritura sin endpoint de revelación.
 */
final class CompanyDataDoesNotLeakIntoQuotesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
    }

    private function publishCompanyWithFakeBankAccount(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/admin/company', [
            'legal_name' => 'Systek Company S.A.S.', 'nit' => '901704107-1',
            'address' => 'Cra 75 # 28-21, Belén, Medellín', 'phone' => '3045869886',
            'email' => 'stip@systekcompany.io', 'website' => 'https://systekcompany.io',
            'signer_name' => 'Jhonatan Stip Gutierrez', 'signer_title' => 'Gerente',
            'bank_account' => [
                'bank_name' => 'Banco Ficticio de Pruebas', 'account_type' => 'savings',
                'account_number' => '5566778899', 'account_number_confirmation' => '5566778899',
                'account_holder' => 'Titular de prueba',
            ],
            'reason' => 'Configuración completa de prueba',
        ])->assertCreated();
    }

    private function draftQuote(): array
    {
        $quoter = User::factory()->create(['role' => 'quoter']);
        Sanctum::actingAs($quoter);
        $id = $this->postJson('/api/v1/quotes', [
            'client_id' => '00000000-0000-4000-8000-000000000001', 'site_id' => '00000000-0000-4000-8000-000000000002',
            'lines' => [['price_version_id' => '00000000-0000-4000-8000-000000000004', 'quantity' => '8', 'discount_bps' => 0]],
            'family' => 'cctv',
            'scope' => 'Ocho cámaras.', 'exclusions' => 'Sin obra civil.', 'payment_terms' => 'Contado.', 'warranty' => 'Por confirmar.',
            'validity_terms' => 'Vigencia de 15 días calendario.', 'validity_days' => 15,
        ])->assertCreated()->json('data.id');

        return ['id' => $id, 'owner' => $quoter];
    }

    public function test_quote_show_issuer_never_includes_signer_or_bank_account_for_any_role(): void
    {
        $this->publishCompanyWithFakeBankAccount();
        ['id' => $id, 'owner' => $owner] = $this->draftQuote();

        foreach (['admin', 'approver'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role, 'active' => true]));
            $response = $this->getJson('/api/v1/quotes/'.$id)->assertOk();
            $response->assertJsonMissingPath('data.issuer.signer_name')
                ->assertJsonMissingPath('data.issuer.signer_title')
                ->assertJsonMissingPath('data.issuer.bank_account')
                ->assertJsonMissingPath('data.issuer.bank_account_summary')
                ->assertJsonPath('data.issuer.legal_name', 'Systek Company S.A.S.');
            $this->assertStringNotContainsString('5566778899', $response->getContent());
            $this->assertStringNotContainsString('Jhonatan Stip Gutierrez', $response->getContent());
        }

        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/quotes/'.$id)->assertOk()
            ->assertJsonMissingPath('data.issuer.signer_name')
            ->assertJsonMissingPath('data.issuer.bank_account');
    }

    public function test_pdf_view_data_never_includes_signer_or_bank_account(): void
    {
        $this->publishCompanyWithFakeBankAccount();
        ['id' => $id] = $this->draftQuote();

        $renderedQuote = null;
        View::composer('quotes.draft-pdf', function ($view) use (&$renderedQuote): void {
            $renderedQuote = $view->getData()['quote'];
        });

        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'active' => true]));
        $response = $this->get('/api/v1/quotes/'.$id.'/pdf')->assertOk();

        $this->assertArrayNotHasKey('bank_account', $renderedQuote['issuer']);
        $this->assertArrayNotHasKey('signer_name', $renderedQuote['issuer']);
        $this->assertArrayNotHasKey('signer_title', $renderedQuote['issuer']);
        $this->assertSame('Systek Company S.A.S.', $renderedQuote['issuer']['legal_name']);

        $html = view('quotes.draft-pdf', ['quote' => $renderedQuote])->render();
        $this->assertStringNotContainsString('5566778899', $html);
        $this->assertStringNotContainsString('Jhonatan Stip Gutierrez', $html);
        $this->assertStringNotContainsString('5566778899', $response->getContent());
    }
}
