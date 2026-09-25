<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\InitialConfigurationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class InitialConfigurationSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_the_issuer_company_and_clauses_and_is_idempotent(): void
    {
        $this->seed(InitialConfigurationSeeder::class);

        $this->assertDatabaseCount('company_versions', 1);
        $this->assertDatabaseHas('company_versions', [
            'legal_name' => 'Systek Company S.A.S.', 'nit' => '901704107-1', 'origin' => 'initial_load',
        ]);
        $bankAccount = DB::table('company_versions')->value('bank_account');
        $this->assertNull($bankAccount);

        $clauseCount = DB::table('clauses')->count();
        $this->assertSame(56, $clauseCount);
        $this->assertSame(56, DB::table('clause_versions')->where('status', 'current')->where('version', 1)->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'company.published']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'clause.created']);
        $clauseAuditCount = DB::table('audit_logs')->where('action', 'clause.created')->count();
        $this->assertSame(56, $clauseAuditCount);
        $noteContainedInAll = DB::table('audit_logs')->where('action', 'clause.created')
            ->pluck('details')
            ->every(fn (?string $details): bool => (json_decode((string) $details, true)['note'] ?? null) === 'Redacción inicial propuesta');
        $this->assertTrue($noteContainedInAll);

        // Segunda ejecución: no debe crear versiones ni cláusulas nuevas.
        $this->seed(InitialConfigurationSeeder::class);
        $this->assertDatabaseCount('company_versions', 1);
        $this->assertSame($clauseCount, DB::table('clauses')->count());
        $this->assertSame($clauseAuditCount, DB::table('audit_logs')->where('action', 'clause.created')->count());
    }

    public function test_does_not_overwrite_an_admin_edited_company_version(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/admin/company', [
            'legal_name' => 'Otra Razón Social S.A.S.', 'reason' => 'Edición previa del administrador',
        ])->assertCreated();

        $this->seed(InitialConfigurationSeeder::class);

        $this->assertDatabaseCount('company_versions', 1);
        $this->assertDatabaseHas('company_versions', ['legal_name' => 'Otra Razón Social S.A.S.']);
    }

    public function test_does_not_overwrite_an_admin_created_clause_with_the_same_title(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/admin/clauses', [
            'family' => 'cctv', 'type' => 'scope_base', 'title' => 'Alcance base',
            'body' => 'Redacción del administrador, distinta de la propuesta inicial.',
            'is_default' => true, 'reason' => 'Redacción propia del administrador',
        ])->assertCreated();

        $this->seed(InitialConfigurationSeeder::class);

        $clauseId = DB::table('clauses')
            ->where('family', 'cctv')->where('type', 'scope_base')->where('title', 'Alcance base')->value('id');
        $body = DB::table('clause_versions')->where('clause_id', $clauseId)->value('body');
        $this->assertSame('Redacción del administrador, distinta de la propuesta inicial.', $body);
        $this->assertSame(1, DB::table('clause_versions')->where('clause_id', $clauseId)->count());
    }
}
