<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class BffSecretTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Route::middleware('api')->get('/api/v1/_bff-probe', fn (Request $request) => response()->json(['ip' => $request->ip(), 'secure' => $request->isSecure()]));
    }

    protected function tearDown(): void
    {
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
        parent::tearDown();
    }

    public function test_without_a_configured_secret_nothing_is_required_outside_production(): void
    {
        config(['security.bff_secret' => '']);
        $this->getJson('/api/v1/_bff-probe')->assertOk();
    }

    public function test_in_production_without_a_secret_the_api_fails_closed(): void
    {
        config(['security.bff_secret' => '']);
        $this->app['env'] = 'production';
        $this->getJson('/api/v1/_bff-probe')->assertStatus(503);
    }

    public function test_missing_or_wrong_secret_gets_a_plain_404(): void
    {
        config(['security.bff_secret' => 'secreto-de-prueba-largo-0123456789']);
        $this->getJson('/api/v1/_bff-probe')->assertNotFound();
        $this->getJson('/api/v1/_bff-probe', ['X-BFF-Secret' => 'otro'])->assertNotFound();
        $this->postJson('/api/v1/auth/login', ['email' => 'a@b.co', 'password' => 'x'])->assertNotFound();
    }

    public function test_valid_secret_is_accepted_and_the_forwarded_client_ip_is_trusted(): void
    {
        config(['security.bff_secret' => 'secreto-de-prueba-largo-0123456789']);
        $this->getJson('/api/v1/_bff-probe', ['X-BFF-Secret' => 'secreto-de-prueba-largo-0123456789', 'X-Forwarded-For' => '203.0.113.9', 'X-Forwarded-Proto' => 'https'])
            ->assertOk()->assertJsonPath('ip', '203.0.113.9')->assertJsonPath('secure', true);
    }

    public function test_forwarded_headers_are_ignored_without_the_secret_configured(): void
    {
        config(['security.bff_secret' => '']);
        $this->getJson('/api/v1/_bff-probe', ['X-Forwarded-For' => '203.0.113.9'])->assertOk()->assertJsonMissing(['ip' => '203.0.113.9']);
    }
}
