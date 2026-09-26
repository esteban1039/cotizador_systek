<?php

namespace Tests\Feature;

use App\Mail\PasswordResetMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

final class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_PASSWORD = 'Another-strong-pass-456';

    protected function setUp(): void
    {
        parent::setUp();
        config(['security.frontend_url' => 'https://app.example.test']);
        Mail::fake();
        RateLimiter::clear('reset-request:'.hash('sha256', 'ana@systek.test'));
    }

    private function user(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['email' => 'ana@systek.test', 'role' => 'quoter', 'active' => true, 'password' => 'Strong-password-123'], $attributes));
    }

    /** @return array{0: string, 1: string} email y token en claro tomados del enlace enviado */
    private function requestLink(User $user): array
    {
        $this->postJson('/api/v1/auth/password/forgot', ['email' => $user->email])->assertStatus(202);
        $sent = Mail::sent(PasswordResetMail::class)->first();
        $this->assertNotNull($sent);
        $this->assertTrue($sent->hasTo($user->email));
        $this->assertStringStartsWith('https://app.example.test/restablecer#', $sent->url);
        parse_str(explode('#', $sent->url)[1], $parts);

        return [$parts['email'], $parts['token']];
    }

    public function test_forgot_answers_identically_for_unknown_inactive_and_active_accounts(): void
    {
        $this->user(['email' => 'off@systek.test', 'active' => false]);
        $active = $this->user();
        $messages = [
            $this->postJson('/api/v1/auth/password/forgot', ['email' => 'nadie@systek.test'])->assertStatus(202)->json('message'),
            $this->postJson('/api/v1/auth/password/forgot', ['email' => 'off@systek.test'])->assertStatus(202)->json('message'),
            $this->postJson('/api/v1/auth/password/forgot', ['email' => $active->email])->assertStatus(202)->json('message'),
        ];
        $this->assertCount(1, array_unique($messages));
        Mail::assertSentCount(1);
        Mail::assertSent(PasswordResetMail::class, fn ($mail) => $mail->hasTo('ana@systek.test'));
    }

    public function test_only_the_token_hash_is_stored_and_the_link_resets_the_password_once(): void
    {
        $user = $this->user();
        $old = $user->tokens()->create(['name' => 'editor', 'token' => hash('sha256', 'x'), 'abilities' => ['*']]);
        [$email, $token] = $this->requestLink($user);
        $this->assertSame(hash('sha256', $token), DB::table('password_reset_tokens')->value('token'));

        $payload = ['email' => $email, 'token' => $token, 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD];
        $this->postJson('/api/v1/auth/password/reset', $payload)->assertOk();
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $old->id]);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'action' => 'password.reset']);
        $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => self::NEW_PASSWORD])->assertOk();
        $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'Strong-password-123'])->assertUnprocessable();
        // Un solo uso.
        $this->postJson('/api/v1/auth/password/reset', $payload)->assertUnprocessable()->assertJsonValidationErrors('token');
    }

    public function test_a_new_request_invalidates_the_previous_link(): void
    {
        $user = $this->user();
        [, $first] = $this->requestLink($user);
        Mail::fake();
        [, $second] = $this->requestLink($user);
        $this->assertNotSame($first, $second);
        $payload = ['email' => $user->email, 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD];
        $this->postJson('/api/v1/auth/password/reset', $payload + ['token' => $first])->assertUnprocessable();
        $this->postJson('/api/v1/auth/password/reset', $payload + ['token' => $second])->assertOk();
    }

    public function test_expired_wrong_and_mismatched_tokens_are_rejected_without_changing_the_password(): void
    {
        $user = $this->user();
        [$email, $token] = $this->requestLink($user);
        $payload = ['email' => $email, 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD];

        $this->postJson('/api/v1/auth/password/reset', $payload + ['token' => str_repeat('a', 64)])->assertUnprocessable()->assertJsonValidationErrors('token');
        $this->postJson('/api/v1/auth/password/reset', ['email' => 'nadie@systek.test', 'token' => $token] + $payload)->assertUnprocessable()->assertJsonValidationErrors('token');
        $this->postJson('/api/v1/auth/password/reset', array_merge($payload, ['token' => $token, 'password_confirmation' => 'otra']))->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/api/v1/auth/password/reset', ['password' => 'corta', 'password_confirmation' => 'corta', 'token' => $token, 'email' => $email])->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->travel(31)->minutes();
        $this->postJson('/api/v1/auth/password/reset', $payload + ['token' => $token])->assertUnprocessable()->assertJsonValidationErrors('token');
        $this->assertTrue(password_verify('Strong-password-123', $user->fresh()->password));
    }

    public function test_deactivating_the_account_after_requesting_blocks_the_reset(): void
    {
        $user = $this->user();
        [$email, $token] = $this->requestLink($user);
        $user->forceFill(['active' => false])->save();
        $this->postJson('/api/v1/auth/password/reset', ['email' => $email, 'token' => $token, 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD])
            ->assertUnprocessable();
    }

    public function test_mfa_accounts_need_a_valid_code_and_the_link_survives_a_failed_attempt(): void
    {
        $google = new Google2FA;
        $secret = $google->generateSecretKey(32);
        $user = $this->user(['mfa_enabled' => true, 'mfa_secret' => $secret]);
        [$email, $token] = $this->requestLink($user);
        $payload = ['email' => $email, 'token' => $token, 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD];

        $this->postJson('/api/v1/auth/password/reset', $payload)->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->postJson('/api/v1/auth/password/reset', $payload + ['code' => '000000'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertTrue(password_verify('Strong-password-123', $user->fresh()->password));
        $this->assertTrue($user->fresh()->mfa_enabled);

        $this->postJson('/api/v1/auth/password/reset', $payload + ['code' => $google->getCurrentOtp($secret)])->assertOk();
        $this->assertTrue($user->fresh()->mfa_enabled);
    }

    public function test_reset_attempts_are_rate_limited_per_account(): void
    {
        $this->user();
        $payload = ['email' => 'ana@systek.test', 'token' => str_repeat('b', 64), 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD];
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/password/reset', $payload)->assertUnprocessable();
        }
        $this->postJson('/api/v1/auth/password/reset', $payload)->assertStatus(429);
    }

    public function test_forgot_is_limited_per_account_without_revealing_it(): void
    {
        $user = $this->user();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/password/forgot', ['email' => $user->email])->assertStatus(202);
        }
        Mail::assertSentCount(3);
    }

    public function test_mail_failure_or_missing_frontend_url_does_not_break_the_response_or_leak(): void
    {
        config(['security.frontend_url' => '']);
        $this->postJson('/api/v1/auth/password/forgot', ['email' => $this->user()->email])->assertStatus(202);
        Mail::assertNothingSent();
    }

    public function test_five_wrong_mfa_codes_invalidate_the_link_even_across_rate_limit_windows(): void
    {
        $google = new Google2FA;
        $secret = $google->generateSecretKey(32);
        $user = $this->user(['mfa_enabled' => true, 'mfa_secret' => $secret]);
        [$email, $token] = $this->requestLink($user);
        $payload = ['email' => $email, 'token' => $token, 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD];

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/password/reset', $payload + ['code' => '000000'])->assertUnprocessable();
        }
        $this->travel(61)->seconds();
        $this->postJson('/api/v1/auth/password/reset', $payload + ['code' => $google->getCurrentOtp($secret)])
            ->assertUnprocessable()->assertJsonValidationErrors('token');
        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'action' => 'password.reset.mfa_failed']);
        $this->assertTrue(password_verify('Strong-password-123', $user->fresh()->password));
    }

    public function test_changing_the_password_or_access_discards_an_issued_link(): void
    {
        $user = $this->user();
        [$email, $token] = $this->requestLink($user);
        $this->actingAs($user)->postJson('/api/v1/auth/password', ['current_password' => 'Strong-password-123', 'password' => 'Changed-pass-789012', 'password_confirmation' => 'Changed-pass-789012'])->assertOk();
        $this->assertDatabaseCount('password_reset_tokens', 0);
        app('auth')->forgetGuards();
        $this->postJson('/api/v1/auth/password/reset', ['email' => $email, 'token' => $token, 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD])
            ->assertUnprocessable();
    }

    public function test_production_with_the_log_mailer_never_sends_or_writes_the_link(): void
    {
        $this->app['env'] = 'production';
        config(['mail.default' => 'log', 'security.bff_secret' => 'test-secret']);
        $this->postJson('/api/v1/auth/password/forgot', ['email' => $this->user()->email], ['X-BFF-Secret' => 'test-secret'])->assertStatus(202);
        Mail::assertNothingSent();
    }
}
