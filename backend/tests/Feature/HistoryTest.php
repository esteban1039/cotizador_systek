<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HistoryTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
    }

    private function record(array $replace = []): array
    {
        return array_replace(['source_id' => 'document-1', 'title' => 'Referencia', 'source_text' => 'Contenido histórico'], $replace);
    }

    private function import(array $records)
    {
        return $this->postJson('/api/v1/admin/history/import', ['records' => $records]);
    }

    public function test_every_endpoint_is_admin_only_and_uncacheable(): void
    {
        $this->getJson('/api/v1/admin/history')->assertUnauthorized()->assertHeader('Cache-Control', 'no-store, private');
        foreach (['quoter', 'approver'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));
            $this->getJson('/api/v1/admin/history')->assertForbidden()->assertHeader('Cache-Control', 'no-store, private');
            $this->import([$this->record()])->assertForbidden();
            $id = Str::uuid();
            $this->getJson('/api/v1/admin/history/'.$id)->assertForbidden();
            $this->postJson('/api/v1/admin/history/'.$id.'/review', [])->assertForbidden();
        }
        $this->assertDatabaseCount('historical_documents', 0);
    }

    public function test_import_deduplicates_normalized_text_and_conflicts_roll_back_batch(): void
    {
        $this->admin();
        $first = $this->import([$this->record(['source_text' => "primera\r\nsegunda"]), $this->record(['source_id' => 'duplicate', 'source_text' => "primera\nsegunda"])])->assertCreated()->assertJsonCount(1, 'imported')->assertJsonCount(1, 'duplicates');
        $id = $first->json('imported.0.id');
        $first->assertJsonPath('duplicates.0.id', $id);
        $this->import([$this->record(['source_text' => "primera\nsegunda"])])->assertCreated()->assertJsonCount(0, 'imported');
        $this->import([$this->record(['source_id' => 'new', 'source_text' => 'different']), $this->record(['source_text' => 'changed'])])->assertConflict()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseCount('historical_documents', 1);
        $this->assertDatabaseMissing('historical_documents', ['source_id' => 'new']);
    }

    public function test_duplicate_source_alias_retains_reference_and_prevents_changed_content(): void
    {
        $this->admin();
        $id = $this->import([$this->record()])->assertCreated()->json('imported.0.id');
        $this->import([$this->record(['source_id' => 'alias-B', 'title' => 'Copia B', 'source_url' => 'https://drive.google.com/file/d/B'])])->assertCreated()->assertJsonPath('duplicates.0.id', $id);
        $this->getJson('/api/v1/admin/history/'.$id)->assertOk()->assertJsonCount(2, 'data.sources');
        $this->assertDatabaseHas('historical_document_sources', ['source_id' => 'alias-B', 'document_id' => $id, 'title' => 'Copia B', 'source_url' => 'https://drive.google.com/file/d/B']);
        $this->import([$this->record(['source_id' => 'new-C', 'source_text' => 'Nuevo']), $this->record(['source_id' => 'alias-B', 'source_text' => 'Alterado'])])->assertConflict();
        $this->assertDatabaseCount('historical_documents', 1);
        $this->assertDatabaseCount('historical_document_sources', 2);
        $this->assertDatabaseMissing('historical_document_sources', ['source_id' => 'new-C']);
    }

    public function test_review_is_explicit_final_and_preserves_source_without_commercial_writes(): void
    {
        $this->admin();
        $client = Client::create(['id' => (string) Str::uuid(), 'name' => 'ACME', 'nit' => '900123456']);
        $counts = collect(['clients', 'catalog_items', 'price_versions', 'quotes'])->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()])->all();
        $id = $this->import([$this->record(['client_nit' => '900.123.456'])])->assertCreated()->json('imported.0.id');
        $this->getJson('/api/v1/admin/history/'.$id)->assertOk()->assertJsonPath('candidates.0.id', $client->id)->assertJsonPath('candidates.0.match', 'nit');
        $this->postJson('/api/v1/admin/history/'.$id.'/review', ['decision' => 'approved', 'reason' => 'Revisado'])->assertUnprocessable();
        $this->postJson('/api/v1/admin/history/'.$id.'/review', ['decision' => 'approved', 'client_id' => $client->id, 'reason' => 'Revisado'])->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.source_text', 'Contenido histórico')->assertJsonPath('data.linked_client_id', $client->id);
        $this->postJson('/api/v1/admin/history/'.$id.'/review', ['decision' => 'rejected', 'reason' => 'Cambio'])->assertConflict();
        foreach ($counts as $table => $count) {
            $this->assertDatabaseCount($table, $count);
        }
        $this->assertStringNotContainsString('Contenido histórico', json_encode(DB::table('audit_logs')->get()));
    }

    public function test_validation_rejects_unsafe_urls_and_oversized_or_unknown_data(): void
    {
        $this->admin();
        foreach (['https://evil.test/a', 'https://drive.google.com.evil.test/a', 'https://user@drive.google.com/a', 'http://drive.google.com/a', 'javascript:alert(1)', 'https://drive.google.com:444/a'] as $url) {
            $this->import([$this->record(['source_url' => $url])])->assertUnprocessable()->assertHeader('Cache-Control', 'no-store, private');
        }
        $this->import([$this->record(['source_text' => str_repeat('a', 20001)])])->assertUnprocessable();
        $this->import(array_fill(0, 21, $this->record()))->assertUnprocessable();
        $this->import([$this->record(['family' => 'unknown'])])->assertUnprocessable();
        $this->import([$this->record(['source_text' => str_repeat('x', 1048577)])])->assertStatus(413);
        $this->assertDatabaseCount('historical_documents', 0);
    }

    public function test_list_filters_omit_source_and_rejection_does_not_link(): void
    {
        $this->admin();
        $id = $this->import([$this->record(['source_url' => 'https://docs.google.com/document/d/source'])])->assertCreated()->json('imported.0.id');
        $this->getJson('/api/v1/admin/history?q=Referencia&status=pending')->assertOk()->assertJsonPath('total', 1)->assertJsonMissingPath('data.0.source_text');
        $this->postJson('/api/v1/admin/history/'.$id.'/review', ['decision' => 'rejected', 'reason' => 'Sin respaldo'])->assertOk()->assertJsonPath('data.linked_client_id', null);
        $this->getJson('/api/v1/admin/history?status=pending')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/v1/admin/history?status=anything')->assertUnprocessable();
    }
}
