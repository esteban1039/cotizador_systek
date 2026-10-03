<?php

namespace Tests\Feature;

use App\Domain\Quotes\ClauseText;
use App\Models\Clause;
use App\Models\ClauseVersion;
use App\Models\Company;
use App\Models\CompanyVersion;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class QuoteEmissionTest extends TestCase
{
    use RefreshDatabase;

    private const ACCOUNT = '5550001234567';

    private User $author;

    private User $approver;

    private User $admin;

    private string $companyId;

    /** @var array<string, string>|null */
    private ?array $clauseCache = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->author = User::factory()->create(['role' => 'quoter']);
        $this->approver = User::factory()->create(['role' => 'approver']);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->publishCompany(requires: false);
        DB::table('commercial_rules')->insert(['family' => 'cctv', 'minimum_margin_bps' => 3000, 'max_discount_bps' => 500, 'review_above_cents' => 100000000, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function publishCompany(bool $requires, bool $withBank = true, bool $withSigner = true): void
    {
        $company = Company::query()->firstOrCreate(['code' => 'issuer'], ['id' => (string) Str::uuid()]);
        $this->companyId = $company->id;
        $current = CompanyVersion::query()->where('company_id', $company->id)->where('status', 'current')->first();
        $current?->update(['status' => 'historical']);
        $factory = CompanyVersion::factory()->state([
            'company_id' => $company->id, 'version' => ($current->version ?? 0) + 1, 'nit' => '9017041071',
            'emission_requires_authorization' => $requires,
            'signer_name' => $withSigner ? 'Firmante Ficticio' : null,
        ]);
        if ($withBank) {
            $factory = $factory->state(['bank_account' => [
                'bank_name' => 'Banco Ficticio de Pruebas', 'account_type' => 'savings', 'account_number' => self::ACCOUNT, 'account_holder' => null,
            ]]);
        }
        $factory->create();
    }

    /** @return array<string, string> */
    private function clauses(): array
    {
        if ($this->clauseCache !== null) {
            return $this->clauseCache;
        }
        $make = function (string $type, string $title, string $body): string {
            $clause = Clause::factory()->create(['family' => 'cctv', 'type' => $type, 'title' => $title, 'is_default' => true]);

            return ClauseVersion::factory()->create(['clause_id' => $clause->id, 'body' => $body, 'body_hash' => ClauseText::hash($body)])->id;
        };

        return $this->clauseCache = [
            'payment' => $make('payment', 'Contado', 'Contado.'),
            'warranty' => $make('warranty', 'Garantía estándar', 'Por confirmar.'),
            'validity' => $make('validity', 'Vigencia estándar', 'Vigencia de 15 días calendario.'),
        ];
    }

    private ?string $siteId = '00000000-0000-4000-8000-000000000002';

    private function payload(array $clauseVersions): array
    {
        return [
            'client_id' => '00000000-0000-4000-8000-000000000001', 'site_id' => $this->siteId,
            'lines' => [['price_version_id' => '00000000-0000-4000-8000-000000000004', 'quantity' => '8', 'discount_bps' => 0]],
            'family' => 'cctv', 'scope' => 'Ocho cámaras.', 'exclusions' => 'Sin obra civil.', 'payment_terms' => 'Contado.',
            'warranty' => 'Por confirmar.', 'validity_terms' => 'Vigencia de 15 días calendario.', 'validity_days' => 15,
            'clause_versions' => $clauseVersions,
        ];
    }

    /** Cotización aprobada por otra persona (por defecto). */
    private function approved(?array $clauseVersions = null, ?string $reviseFrom = null): string
    {
        Sanctum::actingAs($this->author);
        $clauseVersions ??= $this->clauses();
        $id = $reviseFrom
            ? $this->postJson("/api/v1/quotes/{$reviseFrom}/revisions", $this->payload($clauseVersions))->assertCreated()->json('data.id')
            : $this->postJson('/api/v1/quotes', $this->payload($clauseVersions))->assertCreated()->json('data.id');
        $this->postJson("/api/v1/quotes/{$id}/submit", ['reason' => 'Lista para revisión.'])->assertOk();
        Sanctum::actingAs($this->approver);
        $this->postJson("/api/v1/quotes/{$id}/review", ['decision' => 'approve', 'reason' => 'Aprobada para pruebas.'])->assertOk();

        return $id;
    }

    private function issue(string $id, User $user, string $reason = 'Emisión de prueba.')
    {
        Sanctum::actingAs($user);

        return $this->postJson("/api/v1/quotes/{$id}/issue", ['reason' => $reason]);
    }

    public function test_quote_without_site_is_approved_and_issued_with_official_pdf(): void
    {
        $this->siteId = null;
        $id = $this->approved();
        $this->issue($id, $this->author)->assertCreated()->assertJsonPath('data.status', 'issued');
        $this->assertNull(DB::table('quotes')->find($id)->site_id);
        $this->get("/api/v1/quotes/{$id}/official-pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_author_issues_when_authorization_is_disabled_and_download_matches_hash(): void
    {
        $id = $this->approved();
        $response = $this->issue($id, $this->author)->assertCreated()
            ->assertJsonPath('data.status', 'issued')->assertJsonPath('data.emission.quote_number', 'COT-'.now('America/Bogota')->year.'-0001')
            ->assertJsonPath('data.emission.version_label', 'V1');
        $emission = $response->json('data.emission');
        $this->assertSame('issued', DB::table('quotes')->find($id)->status);
        $this->assertDatabaseHas('quote_emission_files', ['emission_id' => $emission['id']]);

        $download = $this->get("/api/v1/quotes/{$id}/official-pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame($emission['pdf_sha256'], hash('sha256', $download->getContent()));
        $this->assertStringStartsWith('%PDF-', $download->getContent());
        $this->assertStringContainsString('no-store', $download->headers->get('Cache-Control'));
        $this->assertStringContainsString('COT-', $download->headers->get('Content-Disposition'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'quote.issued', 'subject_id' => $id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'quote.official_pdf_downloaded', 'subject_id' => $id]);
    }

    public function test_roles_with_authorization_disabled(): void
    {
        $this->getJson('/api/v1/quotes/00000000-0000-4000-8000-000000000001/official-pdf')->assertUnauthorized();
        $this->postJson('/api/v1/quotes/00000000-0000-4000-8000-000000000001/issue', ['reason' => 'Emisión.'])->assertUnauthorized();

        $id = $this->approved();
        $this->issue($id, $this->approver)->assertForbidden();
        $this->issue($id, User::factory()->create(['role' => 'quoter']))->assertNotFound();
        $this->issue($id, $this->admin)->assertCreated();
    }

    public function test_roles_with_authorization_enabled(): void
    {
        $this->publishCompany(requires: true);
        $id = $this->approved();
        $this->issue($id, $this->author)->assertForbidden();
        $this->issue($id, $this->approver)->assertCreated()->assertJsonPath('data.emission.issued_by', $this->approver->name);

        $second = $this->approved();
        $this->issue($second, $this->admin)->assertCreated();

        // Admin autor de la cotización: con autorización activada debe emitir otra persona.
        Sanctum::actingAs($this->admin);
        $own = $this->postJson('/api/v1/quotes', $this->payload($this->clauses()))->assertCreated()->assertJsonPath('data.status', 'approved')->json('data.id');
        $this->issue($own, $this->admin)->assertForbidden();
    }

    public function test_validation_and_state_conflicts(): void
    {
        Sanctum::actingAs($this->author);
        $draft = $this->postJson('/api/v1/quotes', $this->payload($this->clauses()))->assertCreated()->json('data.id');
        $this->postJson("/api/v1/quotes/{$draft}/issue", [])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->issue($draft, $this->author)->assertStatus(409);
        $this->postJson("/api/v1/quotes/{$draft}/submit", ['reason' => 'Lista para revisión.'])->assertOk();
        $this->issue($draft, $this->author)->assertStatus(409);

        $id = $this->approved();
        Sanctum::actingAs($this->author);
        $revision = $this->postJson("/api/v1/quotes/{$id}/revisions", $this->payload($this->clauses()))->assertCreated()->json('data.id');
        $this->issue($id, $this->author)->assertStatus(409);
        $this->assertNotEmpty($revision);

        $ok = $this->approved();
        $this->issue($ok, $this->author)->assertCreated();
        $this->issue($ok, $this->author)->assertStatus(409);
        $this->assertSame(1, DB::table('quote_emissions')->where('quote_id', $ok)->count());
    }

    public function test_blocks_with_422_when_conditions_changed(): void
    {
        $id = $this->approved();
        $this->travel(20)->days();
        $this->issue($id, $this->author)->assertUnprocessable();
        $this->travelBack();

        $id = $this->approved();
        DB::table('price_versions')->where('id', '00000000-0000-4000-8000-000000000004')->update(['status' => 'historical']);
        $this->issue($id, $this->author)->assertUnprocessable();
        DB::table('price_versions')->where('id', '00000000-0000-4000-8000-000000000004')->update(['status' => 'approved']);

        $clauses = $this->clauses();
        $id = $this->approved($clauses);
        ClauseVersion::query()->whereKey($clauses['payment'])->update(['status' => 'historical']);
        $this->issue($id, $this->author)->assertUnprocessable();
        ClauseVersion::query()->whereKey($clauses['payment'])->update(['status' => 'current']);

        $id = $this->approved();
        DB::table('clients')->where('id', '00000000-0000-4000-8000-000000000001')->update(['withholds_vat' => true]);
        $this->issue($id, $this->author)->assertUnprocessable();
        DB::table('clients')->where('id', '00000000-0000-4000-8000-000000000001')->update(['withholds_vat' => false]);

        $id = $this->approved();
        DB::table('quote_reviews')->where('quote_id', $id)->where('decision', 'approve')->update(['user_id' => $this->author->id]);
        $this->issue($id, $this->author)->assertUnprocessable();
    }

    public function test_incomplete_company_blocks_issue(): void
    {
        $this->publishCompany(requires: false, withBank: false);
        $id = $this->approved();
        $this->issue($id, $this->author)->assertUnprocessable()->assertJsonValidationErrors('company');
        $this->publishCompany(requires: false, withSigner: false);
        $this->issue($id, $this->author)->assertUnprocessable();
        $this->assertDatabaseCount('quote_emissions', 0);
    }

    public function test_role_is_checked_before_company_validation_details(): void
    {
        $this->publishCompany(requires: true, withBank: false);
        $id = $this->approved();
        $this->issue($id, $this->author)->assertForbidden();
        $this->issue($id, $this->approver)->assertUnprocessable()->assertJsonValidationErrors('company');
    }

    public function test_emitted_document_is_immutable_and_never_leaks_the_account(): void
    {
        $id = $this->approved();
        $snapshotBefore = DB::table('quotes')->find($id)->snapshot;
        $emission = $this->issue($id, $this->author)->assertCreated()->json('data.emission');
        $bytes = $this->get("/api/v1/quotes/{$id}/official-pdf")->getContent();

        $this->publishCompany(requires: true);
        DB::table('price_versions')->update(['status' => 'historical', 'price_cents' => 1]);
        $this->assertSame($snapshotBefore, DB::table('quotes')->find($id)->snapshot);
        $this->assertSame($bytes, $this->get("/api/v1/quotes/{$id}/official-pdf")->getContent());
        $this->assertSame($emission['snapshot_sha256'], hash('sha256', $snapshotBefore));

        $shown = $this->getJson("/api/v1/quotes/{$id}")->assertOk()->assertJsonPath('data.status', 'issued')->assertJsonPath('data.emission.id', $emission['id'])
            ->assertJsonPath('data.can_issue', false)->assertJsonPath('data.emission_allowed', false)->getContent();
        $haystacks = [$shown, json_encode(DB::table('audit_logs')->get()), json_encode(DB::table('quote_emissions')->get()), json_encode(DB::table('quotes')->get())];
        foreach ($haystacks as $haystack) {
            $this->assertStringNotContainsString(self::ACCOUNT, $haystack);
        }
        $this->assertStringNotContainsString(base64_encode(self::ACCOUNT), json_encode(DB::table('quote_emission_files')->get()));
        $this->assertStringNotContainsString('%PDF', (string) DB::table('quote_emission_files')->value('content'));
        $this->assertStringContainsString('4567', json_encode(DB::table('quote_emissions')->value('bank_summary')));
    }

    public function test_tampered_file_fails_integrity_check_without_delivering_bytes(): void
    {
        $id = $this->approved();
        $emission = $this->issue($id, $this->author)->assertCreated()->json('data.emission');
        DB::table('quote_emission_files')->where('emission_id', $emission['id'])->update(['content' => Crypt::encryptString(base64_encode('%PDF-alterado'))]);
        $this->get("/api/v1/quotes/{$id}/official-pdf")->assertStatus(500);
        $this->assertDatabaseHas('audit_logs', ['action' => 'quote.emission_integrity_failed', 'subject_id' => $id]);
        DB::table('quote_emission_files')->where('emission_id', $emission['id'])->update(['content' => 'no-es-cifrado']);
        $this->get("/api/v1/quotes/{$id}/official-pdf")->assertStatus(500);
    }

    public function test_official_pdf_is_stored_in_s3_and_served_with_integrity_check(): void
    {
        Storage::fake('official_pdfs');
        config(['quotes.official_pdf_storage' => 's3']);
        $id = $this->approved();
        $emission = $this->issue($id, $this->author)->assertCreated()->json('data.emission');

        $file = DB::table('quote_emission_files')->where('emission_id', $emission['id'])->first();
        $this->assertSame($emission['id'].'.pdf', $file->object_key);
        $this->assertNull($file->content);
        Storage::disk('official_pdfs')->assertExists($file->object_key);
        $bytes = $this->get("/api/v1/quotes/{$id}/official-pdf")->assertOk()->getContent();
        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertSame($emission['pdf_sha256'], hash('sha256', $bytes));
        $this->assertSame($bytes, Storage::disk('official_pdfs')->get($file->object_key));

        Storage::disk('official_pdfs')->put($file->object_key, '%PDF-alterado');
        $this->get("/api/v1/quotes/{$id}/official-pdf")->assertStatus(500);
        $this->assertDatabaseHas('audit_logs', ['action' => 'quote.emission_integrity_failed', 'subject_id' => $id]);
        Storage::disk('official_pdfs')->delete($file->object_key);
        $this->get("/api/v1/quotes/{$id}/official-pdf")->assertStatus(500);
    }

    public function test_pdfs_already_archived_in_the_database_are_still_served_after_switching_to_s3(): void
    {
        Storage::fake('official_pdfs');
        $id = $this->approved();
        $this->issue($id, $this->author)->assertCreated();
        $before = $this->get("/api/v1/quotes/{$id}/official-pdf")->assertOk()->getContent();
        config(['quotes.official_pdf_storage' => 's3']);
        $this->assertSame($before, $this->get("/api/v1/quotes/{$id}/official-pdf")->getContent());
    }

    public function test_failed_s3_upload_prevents_the_emission(): void
    {
        config(['quotes.official_pdf_storage' => 's3']);
        Storage::shouldReceive('disk')->with('official_pdfs')->andThrow(new \RuntimeException('S3 no disponible'));
        $id = $this->approved();
        $this->issue($id, $this->author)->assertStatus(500);
        $this->assertDatabaseCount('quote_emissions', 0);
        $this->assertDatabaseCount('quote_emission_files', 0);
        $this->assertSame('approved', DB::table('quotes')->where('id', $id)->value('status'));
    }

    public function test_emitting_a_revision_supersedes_the_previous_emission(): void
    {
        $v1 = $this->approved();
        $first = $this->issue($v1, $this->author)->assertCreated()->json('data.emission');
        $v1Bytes = $this->get("/api/v1/quotes/{$v1}/official-pdf")->getContent();
        $v1Snapshot = DB::table('quotes')->find($v1)->snapshot;

        $v2 = $this->approved(null, $v1);
        $second = $this->issue($v2, $this->author)->assertCreated()->json('data.emission');
        $this->assertSame('V2', $second['version_label']);
        $this->assertNotNull(DB::table('quote_emissions')->find($first['id'])->superseded_at);
        $this->assertSame($second['id'], DB::table('quote_emissions')->find($first['id'])->superseded_by);
        $this->assertNull(DB::table('quote_emissions')->find($second['id'])->superseded_at);
        $this->assertSame($v1Bytes, $this->get("/api/v1/quotes/{$v1}/official-pdf")->getContent());
        $this->assertSame($v1Snapshot, DB::table('quotes')->find($v1)->snapshot);
        $this->getJson("/api/v1/quotes/{$v1}")->assertJsonPath('data.emission.superseded_by_revision', 2);
        $this->assertDatabaseHas('audit_logs', ['action' => 'quote.emission_superseded']);
    }

    public function test_draft_pdf_of_an_issued_quote_is_a_conflict_and_show_reports_blockers(): void
    {
        $id = $this->approved();
        Sanctum::actingAs($this->author);
        $this->getJson("/api/v1/quotes/{$id}")->assertJsonPath('data.can_issue', true)->assertJsonPath('data.issue_blockers', [])->assertJsonPath('data.emission_allowed', true);
        Sanctum::actingAs($this->approver);
        $this->getJson("/api/v1/quotes/{$id}")->assertJsonPath('data.can_issue', false)->assertJsonCount(1, 'data.issue_blockers');
        $this->issue($id, $this->author)->assertCreated();
        $this->get("/api/v1/quotes/{$id}/pdf")->assertStatus(409);
        $this->getJson("/api/v1/quotes/{$id}/official-pdf")->assertOk();
        Sanctum::actingAs(User::factory()->create(['role' => 'quoter']));
        $this->getJson("/api/v1/quotes/{$id}/official-pdf")->assertNotFound();
        $this->assertNull($this->approvedWithoutEmission());
    }

    private function approvedWithoutEmission(): ?array
    {
        $id = $this->approved();
        Sanctum::actingAs($this->author);
        $this->get("/api/v1/quotes/{$id}/official-pdf")->assertNotFound();

        return null;
    }

    public function test_company_publish_toggles_authorization_with_audit(): void
    {
        Sanctum::actingAs($this->admin);
        $body = ['legal_name' => 'Systek Prueba S.A.S.', 'nit' => '901704107-1', 'address' => 'Calle 1', 'phone' => '3045869886', 'email' => 'a@example.com',
            'signer_name' => 'Firmante', 'signer_title' => 'Gerente', 'reason' => 'Activar autorización.', 'emission_requires_authorization' => true];
        $this->postJson('/api/v1/admin/company', $body)->assertCreated()->assertJsonPath('data.emission_requires_authorization', true);
        $audit = DB::table('audit_logs')->where('action', 'company.published')->orderByDesc('id')->first();
        $this->assertStringContainsString('emission_requires_authorization', $audit->details);
        $this->assertStringNotContainsString(self::ACCOUNT, $audit->details);
        unset($body['emission_requires_authorization']);
        $this->postJson('/api/v1/admin/company', $body)->assertCreated()->assertJsonPath('data.emission_requires_authorization', true);
        $this->postJson('/api/v1/admin/company', $body + ['emission_requires_authorization' => 'x'])->assertUnprocessable();
        Sanctum::actingAs($this->approver);
        $this->postJson('/api/v1/admin/company', $body)->assertForbidden();
    }

    public function test_emission_migrations_are_reversible(): void
    {
        $migrations = [
            require database_path('migrations/2026_09_25_000002_add_emission_authorization_to_company_versions.php'),
            require database_path('migrations/2026_09_25_000001_create_quote_emissions.php'),
        ];
        foreach ($migrations as $migration) {
            $migration->down();
        }
        $this->assertFalse(Schema::hasTable('quote_emissions'));
        $this->assertFalse(Schema::hasColumn('company_versions', 'emission_requires_authorization'));
        foreach (array_reverse($migrations) as $migration) {
            $migration->up();
        }
        $this->assertTrue(Schema::hasTable('quote_emission_files'));
        $this->assertTrue(Schema::hasColumn('company_versions', 'emission_requires_authorization'));
    }

    public function test_database_allows_only_one_emission_per_quote(): void
    {
        $id = $this->approved();
        $this->issue($id, $this->author)->assertCreated();
        $row = (array) DB::table('quote_emissions')->where('quote_id', $id)->first();
        $row['id'] = (string) Str::uuid();
        $this->expectException(QueryException::class);
        DB::table('quote_emissions')->insert($row);
    }
}
