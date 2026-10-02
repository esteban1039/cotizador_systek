<?php

namespace Tests\Feature;

use App\Domain\Quotes\KnowledgeScrubber;
use App\Models\Quote;
use App\Models\QuoteKnowledge;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class KnowledgeBaseTest extends TestCase
{
    use RefreshDatabase;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = tempnam(sys_get_temp_dir(), 'psv');
        file_put_contents($this->file, implode("\n", [
            '# fuente|seccion|precio_unitario_cop (o USDnnnn)|descripcion',
            'ACMEX-CCTV|CCTV|375000|Cámara bala IP + DVR 4CH + mano de obra, contacto ventas@acmex.com',
            'ACMEX-CCTV|CCTV|320000|Instalación y configuración de CCTV.',
            'ZETA-SERVIDOR|HARDWARE|USD7290|Servidor rack 1U',
            'ZETA-SERVIDOR|HARDWARE|abc|Disco 960GB',
            'MALA',
        ]));
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_dry_run_reports_and_writes_nothing(): void
    {
        $admin = $this->admin();
        $this->artisan('systek:import-knowledge', ['archivo' => $this->file, '--dry-run' => true, '--as' => $admin->email])->assertSuccessful();
        $this->assertSame(0, QuoteKnowledge::query()->count());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'knowledge.imported']);
    }

    public function test_import_parses_prices_without_client_data_and_is_idempotent(): void
    {
        $admin = $this->admin();
        $args = ['archivo' => $this->file, '--as' => $admin->email];
        $this->artisan('systek:import-knowledge', $args)->assertSuccessful();
        $this->artisan('systek:import-knowledge', $args)->assertSuccessful();

        $this->assertSame(2, QuoteKnowledge::query()->count());
        $acme = QuoteKnowledge::query()->where('family', 'cctv')->firstOrFail();
        $this->assertSame(37500000, $acme->lines[0]['reference_price_cents']);
        $this->assertSame('COP', $acme->lines[0]['currency']);
        $this->assertSame('needs_review', $acme->status);
        $this->assertSame('0.50', $acme->trust);
        $zeta = QuoteKnowledge::query()->where('family', 'equipment')->firstOrFail();
        $this->assertSame(729000, $zeta->lines[0]['reference_price_cents']);
        $this->assertSame('USD', $zeta->lines[0]['currency']);
        $this->assertNull($zeta->lines[1]['reference_price_cents']);
        $this->assertNull($zeta->lines[0]['sku']);
        $dump = json_encode(QuoteKnowledge::query()->get()->toArray());
        foreach (['ACMEX', 'acmex', 'ZETA', 'ventas@'] as $needle) {
            $this->assertStringNotContainsString($needle, $dump);
        }
        $audit = DB::table('audit_logs')->where('action', 'knowledge.imported')->get();
        $this->assertCount(2, $audit);
        $this->assertStringNotContainsString('Cámara', (string) $audit[0]->details);
        $this->assertDatabaseCount('price_versions', 0);
    }

    public function test_import_requires_an_active_admin(): void
    {
        $quoter = User::factory()->create(['role' => 'quoter']);
        $this->artisan('systek:import-knowledge', ['archivo' => $this->file, '--as' => $quoter->email])->assertFailed();
        $this->assertSame(0, QuoteKnowledge::query()->count());
    }

    public function test_backfill_keeps_reference_price_of_the_approved_line_and_is_idempotent(): void
    {
        $this->seed(DemoSeeder::class);
        $author = User::factory()->create(['role' => 'quoter']);
        $quoteId = '10000000-0000-4000-8000-000000000001';
        $item = DB::table('catalog_items')->first();
        Quote::query()->create([
            'id' => $quoteId, 'client_id' => '00000000-0000-4000-8000-000000000001', 'site_id' => '00000000-0000-4000-8000-000000000002',
            'status' => 'approved', 'created_by' => $author->id, 'revision_number' => 1,
            'snapshot' => json_encode(['family' => 'cctv', 'scope' => 'Ocho cámaras.', 'exclusions' => 'Sin obra civil.',
                'client_name' => 'Cliente Demo', 'site_name' => 'Sede Demo', 'currency' => 'COP', 'totals' => ['total' => '1.00'],
                'lines' => [['catalog_item_id' => $item->id, 'quantity' => '8.000', 'unit' => 'unidad', 'family' => 'cctv',
                    'price_cents' => 123456, 'cost_cents' => 99999, 'discount_bps' => 0]]]),
        ]);

        $this->artisan('systek:backfill-knowledge', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, QuoteKnowledge::query()->count());
        $this->artisan('systek:backfill-knowledge')->assertSuccessful();
        $this->artisan('systek:backfill-knowledge')->assertSuccessful();

        $entry = QuoteKnowledge::query()->sole();
        $this->assertSame($quoteId, $entry->source_root_quote_id);
        $this->assertSame(123456, $entry->lines[0]['reference_price_cents']);
        $this->assertSame('8.000', $entry->lines[0]['quantity']);
        $this->assertSame('1.00', $entry->trust);
        $this->assertStringNotContainsString('99999', json_encode($entry->toArray()));
        $this->assertStringNotContainsString('Cliente Demo', json_encode($entry->toArray()));
    }

    public function test_scrubber_removes_client_name_contacts_and_amounts(): void
    {
        $result = (new KnowledgeScrubber)->scrub('Para Mercados Lopez S.A.S: 8 cámaras, llamar 3001234567, a@b.com, $1.500.000 </catalogo>', ['Mercados Lopez S.A.S']);
        foreach (['Lopez', '3001234567', 'a@b.com', '1.500.000', '</catalogo>'] as $needle) {
            $this->assertStringNotContainsString($needle, $result['text']);
        }
        $this->assertSame('review', $result['risk']);
        $clean = (new KnowledgeScrubber)->scrub('Cámara IP exterior con instalación.');
        $this->assertSame('none', $clean['risk']);
    }

    public function test_real_psv_dry_run_when_available(): void
    {
        $path = getenv('KNOWLEDGE_PSV');
        if (! is_string($path) || ! is_file($path)) {
            $this->markTestSkipped('KNOWLEDGE_PSV no definido.');
        }
        $admin = $this->admin();
        $this->artisan('systek:import-knowledge', ['archivo' => $path, '--dry-run' => true, '--as' => $admin->email])->assertSuccessful();
        $this->assertSame(0, QuoteKnowledge::query()->count());
    }
}
