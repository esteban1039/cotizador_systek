<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    private function cleanup(): void
    {
        foreach (['production-admin-access.txt', 'local-admin-access.txt'] as $file) {
            @unlink(storage_path('app/private/'.$file));
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    public function test_it_refuses_to_run_in_other_environments(): void
    {
        $this->assertSame('testing', app()->environment());
        $this->artisan('systek:create-admin', ['--email' => 'a@b.co'])->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_in_production_it_creates_only_the_first_admin_and_stores_credentials_privately(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('systek:create-admin', ['--email' => 'primero@systekcompany.io'])->assertSuccessful();
        $this->assertDatabaseHas('users', ['email' => 'primero@systekcompany.io', 'role' => 'admin']);
        $path = storage_path('app/private/production-admin-access.txt');
        $this->assertFileExists($path);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($path)), -4));

        $this->artisan('systek:create-admin', ['--email' => 'segundo@systekcompany.io'])->assertSuccessful();
        $this->assertDatabaseMissing('users', ['email' => 'segundo@systekcompany.io']);
    }
}
