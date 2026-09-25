<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['role' => 'quoter', 'active' => true, 'password' => 'Strong-password-123'], $attributes));
    }

    private function forgetAuthentication(): void
    {
        app('auth')->forgetGuards();
    }

    public function test_api_authentication_errors_are_json_even_without_json_accept_headers(): void
    {
        $this->get('/api/v1/auth/me')->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
        $this->get('/api/v1/quotes', ['Accept' => 'text/html'])->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
        $this->get('/api/v1/quotes/11111111-1111-4111-8111-111111111111/pdf', ['Accept' => 'application/pdf'])
            ->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_login_returns_individual_expiring_token_and_logout_revokes_it(): void
    {
        $user = $this->user();
        $response = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Strong-password-123'])
            ->assertOk()->assertJsonPath('user.id', $user->id)->assertJsonMissingPath('user.password');
        $token = $response->json('token');
        $this->assertNotEmpty($token);
        $this->assertNotNull($user->tokens()->first()->expires_at);
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.id', $user->id);
        $this->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->forgetAuthentication();
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_invalid_and_inactive_credentials_do_not_create_tokens(): void
    {
        $user = $this->user(['active' => false]);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Strong-password-123'])->assertUnprocessable();
        $this->postJson('/api/v1/auth/login', ['email' => 'unknown@example.test', 'password' => 'wrong'])->assertUnprocessable();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_expired_tokens_and_deactivated_accounts_are_blocked(): void
    {
        $user = $this->user();
        $expired = $user->createToken('expired', ['*'], now()->subMinute())->plainTextToken;
        $this->withToken($expired)->getJson('/api/v1/clients')->assertUnauthorized();
        $valid = $user->createToken('valid', ['*'], now()->addHour())->plainTextToken;
        $user->active = false;
        $user->save();
        $this->forgetAuthentication();
        $this->withToken($valid)->getJson('/api/v1/clients')->assertForbidden();
    }

    public function test_login_rate_limit_blocks_repeated_failures(): void
    {
        $user = $this->user();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong'])->assertUnprocessable();
        }
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Strong-password-123'])->assertStatus(429);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_password_change_checks_current_password_and_revokes_all_sessions_without_logging_secrets(): void
    {
        $user = $this->user();
        $token = $user->createToken('current')->plainTextToken;
        $user->createToken('other');
        $input = ['current_password' => 'wrong', 'password' => 'Replacement-secret-123', 'password_confirmation' => 'Replacement-secret-123'];
        $this->withToken($token)->postJson('/api/v1/auth/password', $input)->assertUnprocessable();
        $this->assertDatabaseCount('personal_access_tokens', 2);
        $input['current_password'] = 'Strong-password-123';
        $this->postJson('/api/v1/auth/password', $input)->assertOk();
        $this->assertTrue(Hash::check($input['password'], $user->fresh()->password));
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $audit = DB::table('audit_logs')->get()->toJson();
        $this->assertStringNotContainsString($input['password'], $audit);
        $this->assertStringNotContainsString($input['current_password'], $audit);
        $this->forgetAuthentication();
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_password_change_reloads_the_locked_user_instead_of_trusting_an_authenticated_snapshot(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);
        DB::table('users')->where('id', $user->id)->update(['password' => Hash::make('Already-changed-secret-123')]);
        $this->postJson('/api/v1/auth/password', [
            'current_password' => 'Strong-password-123', 'password' => 'Replacement-secret-456', 'password_confirmation' => 'Replacement-secret-456',
        ])->assertUnprocessable();
        $this->assertTrue(Hash::check('Already-changed-secret-123', $user->fresh()->password));
    }
}
