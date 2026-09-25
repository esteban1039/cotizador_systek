<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class AdministrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 9, 18)->startOfDay());
        $this->seed(DemoSeeder::class);
    }

    private function asRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role, 'active' => true]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function price(array $changes = []): array
    {
        return array_merge(['price' => '123456.78', 'cost' => '100000.01', 'tax_bps' => 1900, 'valid_until' => '2026-12-31', 'reason' => 'Actualización aprobada de prueba'], $changes);
    }

    public function test_client_nit_is_normalized_and_duplicates_do_not_merge_clients(): void
    {
        $this->asRole('quoter');
        $this->postJson('/api/v1/clients', ['name' => 'Cliente uno', 'nit' => '900.123.456-7'])->assertCreated()->assertJsonPath('data.nit', '9001234567');
        $this->postJson('/api/v1/clients', ['name' => 'Cliente duplicado', 'nit' => '9001234567'])->assertUnprocessable()->assertJsonValidationErrors('nit');
        $this->assertDatabaseHas('clients', ['nit' => '9001234567', 'name' => 'Cliente uno']);
        $this->assertDatabaseMissing('clients', ['name' => 'Cliente duplicado']);
    }

    public function test_client_sites_and_contacts_keep_parent_relationship_and_require_contact_details(): void
    {
        $this->asRole('quoter');
        $id = $this->postJson('/api/v1/clients', ['name' => 'Cliente nuevo'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/clients/'.$id.'/sites', ['name' => 'Sede principal', 'city' => 'Bogotá'])->assertCreated()->assertJsonPath('data.client_id', $id);
        $this->postJson('/api/v1/clients/'.$id.'/contacts', ['name' => 'Sin datos'])->assertUnprocessable();
        $this->postJson('/api/v1/clients/'.$id.'/contacts', ['name' => 'Contacto', 'email' => 'contact@example.test'])->assertCreated()->assertJsonPath('data.client_id', $id);
    }

    public function test_non_admin_roles_cannot_modify_catalog_users_or_rules(): void
    {
        foreach (['quoter', 'approver'] as $role) {
            $this->asRole($role);
            $count = DB::table('audit_logs')->count();
            $this->postJson('/api/v1/admin/catalog', [])->assertForbidden();
            $this->postJson('/api/v1/admin/catalog/00000000-0000-4000-8000-000000000003/prices', $this->price())->assertForbidden();
            $this->patchJson('/api/v1/admin/catalog/00000000-0000-4000-8000-000000000003/active', ['active' => false])->assertForbidden();
            $this->postJson('/api/v1/users', [])->assertForbidden();
            $this->postJson('/api/v1/rules', [])->assertForbidden();
            $this->getJson('/api/v1/audit')->assertForbidden();
            $this->assertDatabaseCount('audit_logs', $count);
        }
        $this->assertDatabaseMissing('catalog_items', ['active' => false]);
    }

    public function test_approver_cannot_create_clients_or_quotes(): void
    {
        $this->asRole('approver');
        $this->postJson('/api/v1/clients', ['name' => 'Forbidden'])->assertForbidden();
        $this->postJson('/api/v1/quotes', [])->assertForbidden();
        $this->postJson('/api/v1/quotes/preview', [])->assertForbidden();
        $this->assertDatabaseMissing('clients', ['name' => 'Forbidden']);
        $this->assertDatabaseCount('quotes', 0);
    }

    public function test_price_publication_preserves_old_amounts_and_archives_previous_version(): void
    {
        $this->asRole('admin');
        $item = $this->postJson('/api/v1/admin/catalog', ['sku' => 'test-01', 'description' => 'Ítem de prueba', 'family' => 'cctv', 'unit' => 'unidad'])->assertCreated()->assertJsonPath('data.sku', 'TEST-01')->json('data.id');
        $first = $this->postJson('/api/v1/admin/catalog/'.$item.'/prices', $this->price())->assertCreated()->assertJsonPath('data.price_cents', 12345678)->assertJsonPath('data.cost_cents', 10000001)->assertJsonPath('data.version', 1)->json('data.id');
        $this->postJson('/api/v1/admin/catalog/'.$item.'/prices', $this->price(['price' => '999999.99']))->assertCreated()->assertJsonPath('data.version', 2);
        $this->assertDatabaseHas('price_versions', ['id' => $first, 'status' => 'historical', 'price_cents' => 12345678, 'cost_cents' => 10000001]);
        $this->assertSame(1, DB::table('price_versions')->where('catalog_item_id', $item)->where('status', 'approved')->count());
    }

    public function test_invalid_decimal_or_expired_publication_cannot_change_current_price(): void
    {
        $this->asRole('admin');
        $price = DB::table('price_versions')->first();
        foreach ([['price' => '1.001'], ['price' => '1e3'], ['cost' => '-1.00'], ['price' => 123.45], ['valid_until' => '2026-09-17']] as $change) {
            $this->postJson('/api/v1/admin/catalog/'.$price->catalog_item_id.'/prices', $this->price($change))->assertUnprocessable();
        }
        $this->assertDatabaseHas('price_versions', ['id' => $price->id, 'status' => $price->status, 'price_cents' => $price->price_cents]);
        $this->assertSame(1, DB::table('price_versions')->where('catalog_item_id', $price->catalog_item_id)->count());
    }

    public function test_user_creation_hides_password_and_access_changes_revoke_tokens_but_self_update_is_blocked(): void
    {
        $admin = $this->asRole('admin');
        $id = $this->postJson('/api/v1/users', ['name' => 'Nuevo usuario', 'email' => 'NEW@example.test', 'password' => 'Secret-password-123', 'role' => 'quoter'])
            ->assertCreated()->assertJsonPath('data.email', 'new@example.test')->assertJsonMissingPath('data.password')->json('data.id');
        User::findOrFail($id)->createToken('existing');
        $this->patchJson('/api/v1/users/'.$id, ['role' => 'approver', 'active' => false])->assertOk();
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $id]);
        $this->patchJson('/api/v1/users/'.$admin->id, ['role' => 'quoter', 'active' => false])->assertUnprocessable();
        $this->assertDatabaseHas('users', ['id' => $admin->id, 'active' => true, 'role' => 'admin']);
        $audit = $this->getJson('/api/v1/audit')->assertOk()->getContent();
        $this->assertStringNotContainsString('Secret-password-123', $audit);
        $this->assertStringNotContainsString(User::findOrFail($id)->password, $audit);
    }
}
