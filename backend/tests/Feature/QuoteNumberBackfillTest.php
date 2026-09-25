<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Migración 2026_09_23_000003_add_quote_numbers: reversibilidad del backfill.
 *
 * Aísla la migración 3 (rollback + re-migrate) sobre cotizaciones "legado"
 * insertadas directamente en BD (sin quote_number), para probar la
 * asignación consecutiva por año y el down() completo, en SQLite :memory:.
 */
class QuoteNumberBackfillTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = 'database/migrations/2026_09_23_000003_add_quote_numbers.php';

    public function test_rollback_and_remigrate_backfills_quote_numbers_by_year_and_order(): void
    {
        $client = Client::create(['id' => (string) Str::uuid(), 'name' => 'ACME', 'nit' => null]);
        $site = Site::create(['id' => (string) Str::uuid(), 'client_id' => $client->id, 'name' => 'Sede', 'city' => 'Medellín']);

        // Migración 3 ya se aplicó vía RefreshDatabase; la revertimos para
        // poder insertar cotizaciones "legado" sin quote_number, como si esta
        // migración nunca hubiera corrido.
        Artisan::call('migrate:rollback', ['--path' => self::MIGRATION_PATH, '--force' => true]);

        $this->assertFalse(Schema::hasColumn('quotes', 'quote_number'));
        $this->assertFalse(Schema::hasTable('quote_number_sequences'));

        $rootLegacy2025 = (string) Str::uuid();
        $rootFirst2026 = (string) Str::uuid();
        $rootSecond2026 = (string) Str::uuid();
        $revisionOfSecond2026 = (string) Str::uuid();

        $insertQuote = function (array $overrides) use ($client, $site): void {
            DB::table('quotes')->insert(array_replace([
                'id' => (string) Str::uuid(),
                'client_id' => $client->id,
                'site_id' => $site->id,
                'status' => 'draft',
                'snapshot' => json_encode(['lines' => []]),
                'root_quote_id' => null,
                'previous_quote_id' => null,
                'revision_number' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ], $overrides));
        };

        // Orden de creación deliberadamente distinto del orden cronológico,
        // para probar que el backfill ordena por created_at (y no por id).
        $insertQuote(['id' => $rootFirst2026, 'created_at' => '2026-01-05 10:00:00', 'updated_at' => '2026-01-05 10:00:00']);
        $insertQuote(['id' => $rootLegacy2025, 'created_at' => '2025-12-01 08:00:00', 'updated_at' => '2025-12-01 08:00:00']);
        $insertQuote(['id' => $rootSecond2026, 'created_at' => '2026-06-01 09:00:00', 'updated_at' => '2026-06-01 09:00:00']);
        $insertQuote([
            'id' => $revisionOfSecond2026,
            'root_quote_id' => $rootSecond2026,
            'previous_quote_id' => $rootSecond2026,
            'revision_number' => 2,
            'created_at' => '2026-06-02 09:00:00',
            'updated_at' => '2026-06-02 09:00:00',
        ]);

        Artisan::call('migrate', ['--path' => self::MIGRATION_PATH, '--force' => true]);

        $this->assertTrue(Schema::hasColumn('quotes', 'quote_number'));
        $this->assertTrue(Schema::hasTable('quote_number_sequences'));

        $this->assertSame('COT-2025-0001', DB::table('quotes')->where('id', $rootLegacy2025)->value('quote_number'));
        $this->assertSame('COT-2026-0001', DB::table('quotes')->where('id', $rootFirst2026)->value('quote_number'));
        $this->assertSame('COT-2026-0002', DB::table('quotes')->where('id', $rootSecond2026)->value('quote_number'));
        $this->assertSame('COT-2026-0002', DB::table('quotes')->where('id', $revisionOfSecond2026)->value('quote_number'));

        $this->assertSame(1, DB::table('quote_number_sequences')->where('year', 2025)->value('last_number'));
        $this->assertSame(2, DB::table('quote_number_sequences')->where('year', 2026)->value('last_number'));

        // El snapshot no se toca por el backfill.
        $this->assertSame('{"lines":[]}', DB::table('quotes')->where('id', $rootFirst2026)->value('snapshot'));

        Artisan::call('migrate:rollback', ['--path' => self::MIGRATION_PATH, '--force' => true]);

        $this->assertFalse(Schema::hasColumn('quotes', 'quote_number'));
        $this->assertFalse(Schema::hasTable('quote_number_sequences'));
        $this->assertDatabaseCount('quotes', 4);
    }
}
