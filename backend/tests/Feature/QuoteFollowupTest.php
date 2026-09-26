<?php

namespace Tests\Feature;

use App\Domain\Quotes\ClauseText;
use App\Models\Clause;
use App\Models\ClauseVersion;
use App\Models\Company;
use App\Models\CompanyVersion;
use App\Models\QuoteFollowup;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Tests\TestCase;

final class QuoteFollowupTest extends TestCase
{
    use RefreshDatabase;

    private User $author;

    private User $approver;

    private User $admin;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->author = User::factory()->create(['role' => 'quoter']);
        $this->other = User::factory()->create(['role' => 'quoter']);
        $this->approver = User::factory()->create(['role' => 'approver']);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $company = Company::query()->create(['id' => (string) Str::uuid(), 'code' => 'issuer']);
        CompanyVersion::factory()->create([
            'company_id' => $company->id, 'version' => 1, 'nit' => '9017041071', 'emission_requires_authorization' => false,
            'signer_name' => 'Firmante Ficticio',
            'bank_account' => ['bank_name' => 'Banco Ficticio', 'account_type' => 'savings', 'account_number' => '5550001234567', 'account_holder' => null],
        ]);
        DB::table('commercial_rules')->insert(['family' => 'cctv', 'minimum_margin_bps' => 3000, 'max_discount_bps' => 500, 'review_above_cents' => 100000000, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @return array<string, string> */
    private function clauses(): array
    {
        $make = function (string $type, string $title, string $body): string {
            $clause = Clause::factory()->create(['family' => 'cctv', 'type' => $type, 'title' => $title, 'is_default' => true]);

            return ClauseVersion::factory()->create(['clause_id' => $clause->id, 'body' => $body, 'body_hash' => ClauseText::hash($body)])->id;
        };

        return [
            'payment' => $make('payment', 'Contado', 'Contado.'),
            'warranty' => $make('warranty', 'Garantía estándar', 'Por confirmar.'),
            'validity' => $make('validity', 'Vigencia estándar', 'Vigencia de 15 días calendario.'),
        ];
    }

    /** Cotización emitida por su autor (aprobada por otra persona). */
    private function issued(): string
    {
        Sanctum::actingAs($this->author);
        $id = $this->postJson('/api/v1/quotes', [
            'client_id' => '00000000-0000-4000-8000-000000000001', 'site_id' => '00000000-0000-4000-8000-000000000002',
            'lines' => [['price_version_id' => '00000000-0000-4000-8000-000000000004', 'quantity' => '8', 'discount_bps' => 0]],
            'family' => 'cctv', 'scope' => 'Ocho cámaras.', 'exclusions' => 'Sin obra civil.', 'payment_terms' => 'Contado.',
            'warranty' => 'Por confirmar.', 'validity_terms' => 'Vigencia de 15 días calendario.', 'validity_days' => 15,
            'clause_versions' => $this->clauses(),
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/quotes/{$id}/submit", ['reason' => 'Lista para revisión.'])->assertOk();
        Sanctum::actingAs($this->approver);
        $this->postJson("/api/v1/quotes/{$id}/review", ['decision' => 'approve', 'reason' => 'Aprobada para pruebas.'])->assertOk();
        Sanctum::actingAs($this->author);
        $this->postJson("/api/v1/quotes/{$id}/issue", ['reason' => 'Emisión de prueba.'])->assertCreated();

        return $id;
    }

    /** @return array<string, mixed> */
    private function event(string $type, array $extra = []): array
    {
        return array_merge(['type' => $type, 'occurred_at' => now()->toIso8601String()], $type === 'sent' ? ['channel' => 'email'] : [], $extra);
    }

    private function record(string $id, string $type, array $extra = [])
    {
        return $this->postJson("/api/v1/quotes/{$id}/followups", $this->event($type, $extra));
    }

    public function test_owner_and_admin_record_a_full_flow_and_status_is_derived(): void
    {
        $id = $this->issued();
        Sanctum::actingAs($this->author);
        $this->getJson("/api/v1/quotes/{$id}/followups")->assertOk()->assertJsonPath('data.commercial_status', 'not_sent')->assertJsonPath('data.can_record_followup', true);
        $this->record($id, 'sent')->assertCreated()->assertJsonPath('data.commercial_status', 'sent');
        $this->record($id, 'sent', ['channel' => 'whatsapp'])->assertCreated()->assertJsonPath('data.commercial_status', 'sent');
        $this->record($id, 'note', ['note' => 'Llamar el lunes.'])->assertCreated()->assertJsonPath('data.commercial_status', 'sent');
        Sanctum::actingAs($this->admin);
        $this->record($id, 'response')->assertCreated()->assertJsonPath('data.commercial_status', 'responded');
        $this->record($id, 'accepted')->assertCreated()->assertJsonPath('data.commercial_status', 'accepted')->assertJsonCount(5, 'data.followups');
        $this->assertSame('issued', DB::table('quotes')->find($id)->status);
        $this->getJson("/api/v1/quotes/{$id}")->assertJsonPath('data.commercial_status', 'accepted')->assertJsonPath('data.can_record_followup', true);
    }

    public function test_permissions_by_role(): void
    {
        $id = $this->issued();
        Sanctum::actingAs($this->other);
        $this->record($id, 'sent')->assertNotFound();
        $this->getJson("/api/v1/quotes/{$id}/followups")->assertNotFound();
        Sanctum::actingAs($this->approver);
        $this->record($id, 'sent')->assertForbidden();
        $this->postJson("/api/v1/quotes/{$id}/followups", ['type' => 'invalid'])->assertForbidden();
        $this->getJson("/api/v1/quotes/{$id}/followups")->assertOk()->assertJsonPath('data.can_record_followup', false);
        $this->getJson("/api/v1/quotes/{$id}")->assertJsonPath('data.can_record_followup', false)->assertJsonPath('data.commercial_status', 'not_sent');
        $this->assertSame(0, QuoteFollowup::query()->count());
    }

    public function test_requests_without_token_are_unauthorized(): void
    {
        $id = (string) Str::uuid();
        $this->getJson("/api/v1/quotes/{$id}/followups")->assertUnauthorized();
        $this->postJson("/api/v1/quotes/{$id}/followups", [])->assertUnauthorized();
    }

    public function test_not_issued_quote_is_a_conflict_and_show_is_null_safe(): void
    {
        $id = $this->issued();
        DB::table('quotes')->where('id', $id)->update(['status' => 'approved']);
        Sanctum::actingAs($this->author);
        $this->record($id, 'sent')->assertStatus(409);
        $this->getJson("/api/v1/quotes/{$id}")->assertOk()->assertJsonPath('data.commercial_status', null)->assertJsonPath('data.can_record_followup', false);
        $this->getJson("/api/v1/quotes/{$id}/followups")->assertOk()->assertJsonPath('data.commercial_status', null);
    }

    public function test_invalid_transitions_are_conflicts(): void
    {
        $id = $this->issued();
        Sanctum::actingAs($this->author);
        foreach (['response', 'accepted'] as $type) {
            $this->record($id, $type)->assertStatus(409);
        }
        $this->record($id, 'rejected', ['note' => 'No.'])->assertStatus(409);
        $this->record($id, 'note', ['note' => 'Nota previa al envío.'])->assertCreated()->assertJsonPath('data.commercial_status', 'not_sent');
        $this->record($id, 'sent')->assertCreated();
        $this->record($id, 'rejected', ['note' => 'Precio alto.'])->assertCreated()->assertJsonPath('data.commercial_status', 'rejected');
        foreach (['sent', 'response', 'accepted', 'rejected'] as $type) {
            $this->record($id, $type, ['note' => 'x'])->assertStatus(409);
        }
        $this->record($id, 'note', ['note' => 'Cliente pide reabrir.'])->assertCreated()->assertJsonPath('data.commercial_status', 'rejected');
        $this->assertSame(4, QuoteFollowup::query()->count());
    }

    public function test_dates_out_of_range_are_rejected(): void
    {
        $id = $this->issued();
        Sanctum::actingAs($this->author);
        $this->record($id, 'sent', ['occurred_at' => now()->addHour()->toIso8601String()])->assertUnprocessable()->assertJsonValidationErrors('occurred_at');
        $this->record($id, 'sent', ['occurred_at' => now()->subDay()->toIso8601String()])->assertUnprocessable()->assertJsonValidationErrors('occurred_at');
        $this->record($id, 'sent', ['occurred_at' => 'no-es-fecha'])->assertUnprocessable();
        $this->assertSame(0, QuoteFollowup::query()->count());
    }

    public function test_field_validation(): void
    {
        $id = $this->issued();
        Sanctum::actingAs($this->author);
        $this->postJson("/api/v1/quotes/{$id}/followups", ['type' => 'sent', 'occurred_at' => now()->toIso8601String()])->assertUnprocessable()->assertJsonValidationErrors('channel');
        $this->record($id, 'sent', ['channel' => 'fax'])->assertUnprocessable()->assertJsonValidationErrors('channel');
        $this->record($id, 'sent')->assertCreated();
        $this->postJson("/api/v1/quotes/{$id}/followups", ['type' => 'rejected', 'occurred_at' => now()->toIso8601String()])->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->record($id, 'note', ['channel' => 'email', 'note' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('channel');
        $this->record($id, 'note', ['note' => str_repeat('a', 1001)])->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->record($id, 'note', ['note' => 'Cuenta 5550001234567'])->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->record($id, 'bogus')->assertUnprocessable()->assertJsonValidationErrors('type');
    }

    public function test_append_only_and_audit_without_note(): void
    {
        $id = $this->issued();
        Sanctum::actingAs($this->author);
        $this->record($id, 'sent')->assertCreated();
        $note = 'Nota confidencial del cliente.';
        $followupId = $this->record($id, 'note', ['note' => $note])->assertCreated()->json('data.followups.1.id');
        $this->putJson("/api/v1/quotes/{$id}/followups/{$followupId}", [])->assertStatus(404);
        $this->patchJson("/api/v1/quotes/{$id}/followups/{$followupId}", [])->assertStatus(404);
        $this->deleteJson("/api/v1/quotes/{$id}/followups/{$followupId}")->assertStatus(404);
        $model = QuoteFollowup::query()->findOrFail($followupId);
        try {
            $model->update(['note' => 'otra']);
            $this->fail('El seguimiento no debe actualizarse.');
        } catch (LogicException) {
            $this->assertSame($note, $model->fresh()->note);
        }
        $audit = DB::table('audit_logs')->where('action', 'quote.followup_recorded')->where('subject_id', $id)->orderByDesc('id')->first();
        $details = json_decode($audit->details, true);
        $this->assertSame($followupId, $details['followup_id']);
        $this->assertSame('note', $details['type']);
        $this->assertSame(mb_strlen($note), $details['note_length']);
        $this->assertArrayHasKey('occurred_at', $details);
        $this->assertStringNotContainsString('confidencial', $audit->details);
    }

    public function test_followup_migration_is_reversible(): void
    {
        $this->assertTrue(Schema::hasTable('quote_followups'));
        $migration = require database_path('migrations/2026_09_26_000001_create_quote_followups.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('quote_followups'));
        $migration->up();
        $this->assertTrue(Schema::hasTable('quote_followups'));
    }
}
