<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class QuotePdfTest extends TestCase
{
    use RefreshDatabase;

    private User $author;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 9, 18)->startOfDay());
        $this->seed(DemoSeeder::class);
        $this->author = User::factory()->create(['role' => 'quoter']);
    }

    private function draft(): string
    {
        Sanctum::actingAs($this->author);

        return $this->postJson('/api/v1/quotes', [
            'client_id' => '00000000-0000-4000-8000-000000000001', 'site_id' => '00000000-0000-4000-8000-000000000002',
            'lines' => [['price_version_id' => '00000000-0000-4000-8000-000000000004', 'quantity' => '8', 'discount_bps' => 0]],
            'family' => 'cctv',
            'scope' => 'Ocho cámaras.', 'exclusions' => 'Sin obra civil.', 'payment_terms' => 'Contado.', 'warranty' => 'Por confirmar.',
            'validity_terms' => 'Vigencia de 15 días calendario.', 'validity_days' => 15,
        ])->assertCreated()->json('data.id');
    }

    public function test_pdf_requires_authentication_and_ownership(): void
    {
        $this->getJson('/api/v1/quotes/00000000-0000-4000-8000-000000000001/pdf')->assertUnauthorized();
        $id = $this->draft();
        Sanctum::actingAs(User::factory()->create(['role' => 'quoter']));
        $this->getJson('/api/v1/quotes/'.$id.'/pdf')->assertNotFound();
        Sanctum::actingAs($this->author);
        $this->getJson('/api/v1/quotes/00000000-0000-4000-8000-000000000001/pdf')->assertNotFound();
    }

    public function test_owner_admin_and_approver_can_download_private_pdf(): void
    {
        $id = $this->draft();
        foreach ([$this->author, User::factory()->create(['role' => 'admin']), User::factory()->create(['role' => 'approver'])] as $user) {
            Sanctum::actingAs($user);
            $response = $this->get('/api/v1/quotes/'.$id.'/pdf')->assertOk()
                ->assertHeader('Content-Type', 'application/pdf')
                ->assertHeader('Content-Disposition', 'attachment; filename="COT-2026-0001-V1-borrador.pdf"')
                ->assertHeader('X-Content-Type-Options', 'nosniff');
            $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $this->assertStringStartsWith('%PDF-', $response->getContent());
            $this->assertStringContainsString('%%EOF', $response->getContent());
        }
    }

    public function test_legacy_quote_without_number_falls_back_to_id_based_filename(): void
    {
        $id = $this->draft();
        $snapshot = json_decode(DB::table('quotes')->find($id)->snapshot, true);
        unset($snapshot['quote_number']);
        DB::table('quotes')->where('id', $id)->update(['quote_number' => null, 'snapshot' => json_encode($snapshot)]);
        Sanctum::actingAs($this->author);
        $this->get('/api/v1/quotes/'.$id.'/pdf')->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="quote-'.$id.'-v1-borrador.pdf"');
    }

    public function test_historical_price_pdf_uses_snapshot_and_preserves_approved_record(): void
    {
        $id = $this->draft();
        DB::table('quotes')->where('id', $id)->update(['status' => 'approved']);
        $before = DB::table('quotes')->find($id);
        DB::table('price_versions')->update(['status' => 'historical', 'price_cents' => 1]);
        $this->get('/api/v1/quotes/'.$id.'/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertEquals($before, DB::table('quotes')->find($id));
        $this->assertDatabaseCount('quotes', 1);
        $this->assertDatabaseCount('quote_reviews', 0);
        $this->getJson('/api/v1/quotes/'.$id)->assertOk()->assertJsonPath('data.emission_allowed', false)
            ->assertJsonPath('data.totals.total', '1904000.00');
    }

    public function test_pdf_whitelists_private_fields_escapes_html_and_supports_legacy_names(): void
    {
        $id = $this->draft();
        $snapshot = json_decode(DB::table('quotes')->find($id)->snapshot, true);
        unset($snapshot['client_name'], $snapshot['site_name']);
        $snapshot['scope'] = '<script>alert("test")</script><img src="https://example.test/private.png">';
        $snapshot['reviews'] = [['reason' => 'Private review']];
        DB::table('quotes')->where('id', $id)->update(['snapshot' => json_encode($snapshot)]);
        $renderedQuote = null;
        View::composer('quotes.draft-pdf', function ($view) use (&$renderedQuote): void {
            $renderedQuote = $view->getData()['quote'];
        });
        $this->get('/api/v1/quotes/'.$id.'/pdf')->assertOk();
        $this->assertSame('Cliente de demostración', $renderedQuote['client_name']);
        $this->assertSame('Sede de prueba', $renderedQuote['site_name']);
        foreach (['reviews', 'profit', 'created_by'] as $key) {
            $this->assertArrayNotHasKey($key, $renderedQuote);
        }
        $this->assertArrayNotHasKey('cost', $renderedQuote['totals']);
        $this->assertArrayNotHasKey('cost_cents', $renderedQuote['lines'][0]);
        $this->assertArrayNotHasKey('cost', $renderedQuote['lines'][0]['amounts']);
        $html = view('quotes.draft-pdf', ['quote' => $renderedQuote])->render();
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img src=', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('BORRADOR - NO VÁLIDO PARA ENVÍO', $html);
    }

    public function test_bank_account_and_signer_configured_never_reach_the_pdf_view_or_html(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'active' => true]));
        $this->postJson('/api/v1/admin/company', [
            'legal_name' => 'Systek Company S.A.S.', 'nit' => '901704107-1',
            'address' => 'Cra 75 # 28-21, Belén, Medellín', 'phone' => '3045869886',
            'email' => 'stip@systekcompany.io', 'website' => 'https://systekcompany.io',
            'signer_name' => 'Jhonatan Stip Gutierrez', 'signer_title' => 'Gerente',
            'bank_account' => [
                'bank_name' => 'Banco Ficticio de Pruebas', 'account_type' => 'savings',
                'account_number' => '9876543210', 'account_number_confirmation' => '9876543210',
                'account_holder' => 'Titular de prueba',
            ],
            'reason' => 'Configuración completa para la prueba de PDF',
        ])->assertCreated();

        $id = $this->draft();
        $renderedQuote = null;
        View::composer('quotes.draft-pdf', function ($view) use (&$renderedQuote): void {
            $renderedQuote = $view->getData()['quote'];
        });
        Sanctum::actingAs($this->author);
        $this->get('/api/v1/quotes/'.$id.'/pdf')->assertOk();

        $this->assertArrayNotHasKey('bank_account', $renderedQuote['issuer']);
        $this->assertArrayNotHasKey('signer_name', $renderedQuote['issuer']);
        $this->assertArrayNotHasKey('signer_title', $renderedQuote['issuer']);
        $this->assertSame('Systek Company S.A.S.', $renderedQuote['issuer']['legal_name']);

        $html = view('quotes.draft-pdf', ['quote' => $renderedQuote])->render();
        $this->assertStringNotContainsString('9876543210', $html);
        $this->assertStringNotContainsString('Titular de prueba', $html);
        $this->assertStringNotContainsString('Banco Ficticio', $html);
        $this->assertStringNotContainsString('Jhonatan Stip Gutierrez', $html);
        $this->assertStringNotContainsString('Gerente', $html);
        $this->assertStringContainsString('Systek Company S.A.S.', $html);
    }
}
