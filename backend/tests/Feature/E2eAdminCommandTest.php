<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class E2eAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $filename = 'e2e-command-test.json';

    protected function tearDown(): void
    {
        $path = storage_path('app/private/'.$this->filename);
        if (is_file($path)) {
            unlink($path);
        }
        parent::tearDown();
    }

    public function test_provisions_private_credentials_without_modifying_existing_users(): void
    {
        $existing = User::factory()->create(['role' => 'admin']);
        $original = $existing->fresh()->getRawOriginal();
        $this->artisan('systek:create-e2e-admin', ['--file' => $this->filename])->assertSuccessful();
        $path = storage_path('app/private/'.$this->filename);
        $credentials = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $user = User::where('email', $credentials['email'])->firstOrFail();
        $this->assertTrue(Hash::check($credentials['password'], $user->password));
        $this->assertSame('admin', $user->role);
        $this->assertSame(0600, fileperms($path) & 0777);
        $this->assertTrue($original === $existing->fresh()->getRawOriginal());
        $before = hash_file('sha256', $path);
        $this->artisan('systek:create-e2e-admin', ['--file' => $this->filename])->assertFailed();
        $this->assertTrue($before === hash_file('sha256', $path));
        $this->assertDatabaseCount('users', 2);
    }

    public function test_refuses_production_and_paths_outside_private_storage(): void
    {
        $this->artisan('systek:create-e2e-admin', ['--file' => '../e2e-test.json'])->assertFailed();
        app()->instance('env', 'production');
        $this->artisan('systek:create-e2e-admin', ['--file' => $this->filename])->assertFailed();
        $this->assertDatabaseCount('users', 0);
        $this->assertFileDoesNotExist(storage_path('app/private/'.$this->filename));
    }
}
