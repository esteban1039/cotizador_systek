<?php

namespace Tests\Feature;

use App\Models\User;
use App\Repositories\Contracts\IdentityRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class MfaTest extends TestCase
{
    use RefreshDatabase;

    private function account(): array
    {
        $user = User::factory()->create(['password' => 'Testing-password-123']);
        $token = $user->createToken('test')->plainTextToken;

        return [$user, $token];
    }

    private function enroll(User $user, string $token): array
    {
        $setup = $this->withToken($token)->postJson('/api/v1/auth/mfa/setup', ['current_password' => 'Testing-password-123'])->assertOk()->json();
        $code = (new Google2FA)->getCurrentOtp($setup['secret']);
        $codes = $this->withToken($token)->postJson('/api/v1/auth/mfa/confirm', ['code' => $code])->assertOk()->json('recovery_codes');

        return [$setup['secret'], $code, $codes];
    }

    public function test_enrollment_encrypts_hides_secrets_and_revokes_sessions(): void
    {
        [$user, $token] = $this->account();
        [$secret, $code, $codes] = $this->enroll($user, $token);
        $this->assertCount(10, $codes);
        $this->assertTrue($secret !== DB::table('users')->where('id', $user->id)->value('mfa_secret'));
        $this->assertTrue($user->fresh()->mfa_enabled);
        $this->assertArrayNotHasKey('mfa_secret', $user->fresh()->toArray());
        $this->assertArrayNotHasKey('mfa_recovery_codes', $user->fresh()->toArray());
        $this->assertSame(0, $user->tokens()->count());
        $login = ['email' => $user->email, 'password' => 'Testing-password-123'];
        $this->postJson('/api/v1/auth/login', $login)->assertUnprocessable()->assertJsonPath('mfa_required', true)->assertHeader('Cache-Control', 'no-store, private');
        $this->postJson('/api/v1/auth/login', $login + ['code' => $code])->assertUnprocessable();
        $this->postJson('/api/v1/auth/login', $login + ['code' => $codes[0]])->assertOk();
        $this->postJson('/api/v1/auth/login', $login + ['code' => $codes[0]])->assertUnprocessable();
        $this->assertCount(9, $user->fresh()->mfa_recovery_codes);
    }

    public function test_setup_requires_password_and_expires_without_enabling(): void
    {
        [$user, $token] = $this->account();
        $this->withToken($token)->postJson('/api/v1/auth/mfa/setup', ['current_password' => 'wrong'])->assertUnprocessable();
        $setup = $this->postJson('/api/v1/auth/mfa/setup', ['current_password' => 'Testing-password-123'])->assertOk()->json();
        $this->travel(11)->minutes();
        $this->postJson('/api/v1/auth/mfa/confirm', ['code' => (new Google2FA)->getCurrentOtp($setup['secret'])])->assertUnprocessable();
        $this->assertFalse($user->fresh()->mfa_enabled);
    }

    public function test_disable_requires_both_factors_and_revokes_tokens(): void
    {
        [$user, $token] = $this->account();
        [, , $codes] = $this->enroll($user, $token);
        $token = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Testing-password-123', 'code' => $codes[0]])->assertOk()->json('token');
        $this->withToken($token)->postJson('/api/v1/auth/mfa/disable', ['current_password' => 'wrong', 'code' => $codes[1]])->assertUnprocessable();
        $this->postJson('/api/v1/auth/mfa/disable', ['current_password' => 'Testing-password-123', 'code' => 'invalid'])->assertUnprocessable();
        $this->postJson('/api/v1/auth/mfa/disable', ['current_password' => 'Testing-password-123', 'code' => $codes[1]])->assertOk();
        $this->assertFalse($user->fresh()->mfa_enabled);
        $this->assertNull($user->fresh()->mfa_secret);
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_invalid_second_factor_hits_limiter_and_never_issues_token(): void
    {
        [$user, $token] = $this->account();
        $this->enroll($user, $token);
        $login = ['email' => $user->email, 'password' => 'Testing-password-123', 'code' => 'invalid'];
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', $login)->assertUnprocessable();
        }
        $this->postJson('/api/v1/auth/login', $login)->assertStatus(429);
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_fresh_totp_works_once_and_active_setup_is_rejected(): void
    {
        [$user, $token] = $this->account();
        [$secret] = $this->enroll($user, $token);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->travel(30)->seconds();
        $code = (new Google2FA)->oathTotp($secret, intdiv(now()->timestamp, 30));
        $login = ['email' => $user->email, 'password' => 'Testing-password-123', 'code' => $code];
        $token = $this->postJson('/api/v1/auth/login', $login)->assertOk()->json('token');
        $this->postJson('/api/v1/auth/login', $login)->assertUnprocessable();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson('/api/v1/auth/mfa/setup', ['current_password' => 'Testing-password-123'])->assertStatus(409);
    }

    public function test_pending_setup_is_invalidated_by_password_and_access_changes(): void
    {
        [$user, $token] = $this->account();
        $this->withToken($token)->postJson('/api/v1/auth/mfa/setup', ['current_password' => 'Testing-password-123'])->assertOk();
        $this->postJson('/api/v1/auth/password', ['current_password' => 'Testing-password-123', 'password' => 'Another-password-123', 'password_confirmation' => 'Another-password-123'])->assertOk();
        $this->assertNull($user->fresh()->mfa_pending_secret);
        $this->assertNull($user->fresh()->mfa_pending_expires_at);
        $user->forceFill(['mfa_pending_secret' => 'pending-secret', 'mfa_pending_expires_at' => now()->addMinutes(10)])->save();
        $repo = app(IdentityRepository::class);
        $repo->transaction(fn () => $repo->updateAccess($repo->findForUpdate($user->id), 'approver', true));
        $this->assertNull($user->fresh()->mfa_pending_secret);
        $this->assertNull($user->fresh()->mfa_pending_expires_at);
    }
}
