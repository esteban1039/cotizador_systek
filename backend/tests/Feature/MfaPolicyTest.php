<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireMfaEnrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class MfaPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role = 'admin'): array
    {
        $user = User::factory()->create(['role' => $role, 'password' => 'Testing-password-123']);

        return [$user, $user->createToken('existing')->plainTextToken];
    }

    public function test_policy_defaults_to_optional_and_can_restrict_existing_tokens_dynamically(): void
    {
        [$user, $token] = $this->account();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.mfa_required', false)->assertJsonPath('data.mfa_enrollment_required', false);
        $this->getJson('/api/v1/clients')->assertOk();
        config(['security.mfa_required_roles' => ['admin', 'approver']]);
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.mfa_required', true)->assertJsonPath('data.mfa_enrollment_required', true);
        $this->getJson('/api/v1/auth/mfa')->assertOk()->assertJsonPath('required', true);
        foreach (app('router')->getRoutes() as $route) {
            if (! in_array(RequireMfaEnrollment::class, $route->middleware(), true) || str_starts_with($route->uri(), 'api/v1/auth/')) {
                continue;
            }
            $uri = str_replace('{id}', str_contains($route->uri(), 'users/{id}') ? '1' : '00000000-0000-4000-8000-000000000000', $route->uri());
            $this->json($route->methods()[0], '/'.$uri)->assertForbidden()->assertJsonPath('code', 'mfa_enrollment_required')->assertHeader('Cache-Control', 'no-store, private');
        }
        $this->assertSame(1, $user->tokens()->count());
        config(['security.mfa_required_roles' => []]);
        $this->getJson('/api/v1/clients')->assertOk();
    }

    public function test_required_role_can_login_enroll_and_then_access_business_but_cannot_disable(): void
    {
        config(['security.mfa_required_roles' => ['admin', 'approver']]);
        [$user] = $this->account('approver');
        $credentials = ['email' => $user->email, 'password' => 'Testing-password-123'];
        $token = $this->postJson('/api/v1/auth/login', $credentials)->assertOk()->assertJsonPath('user.mfa_required', true)->assertJsonPath('user.mfa_enrollment_required', true)->json('token');
        $setup = $this->withToken($token)->postJson('/api/v1/auth/mfa/setup', ['current_password' => $credentials['password']])->assertOk()->json();
        $codes = $this->postJson('/api/v1/auth/mfa/confirm', ['code' => (new Google2FA)->getCurrentOtp($setup['secret'])])->assertOk()->json('recovery_codes');
        $this->assertSame(0, $user->tokens()->count());
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $token = $this->postJson('/api/v1/auth/login', $credentials + ['code' => $codes[0]])->assertOk()->assertJsonPath('user.mfa_enrollment_required', false)->json('token');
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/clients')->assertOk();
        $this->postJson('/api/v1/auth/mfa/disable', ['current_password' => $credentials['password'], 'code' => $codes[1]])->assertForbidden()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertTrue($user->fresh()->mfa_enabled);
        $this->assertCount(9, $user->fresh()->mfa_recovery_codes);
    }

    public function test_unenrolled_user_can_change_password_and_logout_while_unlisted_role_remains_optional(): void
    {
        config(['security.mfa_required_roles' => ['admin']]);
        [$user, $token] = $this->account();
        $this->withToken($token)->postJson('/api/v1/auth/password', ['current_password' => 'Testing-password-123', 'password' => 'New-password-1234', 'password_confirmation' => 'New-password-1234'])->assertOk();
        $this->assertSame(0, $user->tokens()->count());
        $this->app['auth']->forgetGuards();
        $token = $user->createToken('logout')->plainTextToken;
        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
        $this->app['auth']->forgetGuards();
        [, $token] = $this->account('quoter');
        $this->withToken($token)->getJson('/api/v1/clients')->assertOk();
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.mfa_required', false);
    }
}
