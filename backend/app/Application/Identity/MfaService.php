<?php

namespace App\Application\Identity;

use App\Domain\Audit;
use App\Models\User;
use App\Repositories\Contracts\IdentityRepository;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;

final class MfaService
{
    public function __construct(private IdentityRepository $identities, private Google2FA $totp, private MfaPolicy $policy) {}

    public function guarded(string $key, callable $operation): mixed
    {
        abort_if(RateLimiter::tooManyAttempts($key, 5), 429, 'Demasiados intentos. Intenta nuevamente en un minuto.');
        try {
            $result = $this->identities->transaction($operation);
        } catch (ValidationException $exception) {
            // Outside the transaction: a database cache counter must survive rollback.
            RateLimiter::hit($key, 60);
            throw $exception;
        }
        RateLimiter::clear($key);

        return $result;
    }

    public function setup(int $id, string $password): array
    {
        return $this->guarded('mfa:'.$id, function () use ($id, $password) {
            $user = $this->identities->findForUpdate($id);
            $this->checkPassword($user, $password);
            abort_if($user->mfa_enabled, 409, 'La verificación en dos pasos ya está activa.');
            $secret = $this->totp->generateSecretKey(32);
            $expires = now()->addMinutes(10);
            $this->identities->saveMfa($user, ['mfa_pending_secret' => $secret, 'mfa_pending_expires_at' => $expires]);
            Audit::record($id, 'mfa.setup', (string) $id);

            return ['secret' => $secret, 'otpauth_uri' => $this->totp->getQRCodeUrl('Systek', $user->email, $secret), 'expires_at' => $expires->toIso8601String()];
        });
    }

    public function confirm(int $id, string $code): array
    {
        return $this->guarded('mfa:'.$id, function () use ($id, $code) {
            $user = $this->identities->findForUpdate($id);
            abort_unless($user->active, 403);
            if ($user->mfa_enabled || ! $user->mfa_pending_secret || ! $user->mfa_pending_expires_at?->isFuture()) {
                throw ValidationException::withMessages(['code' => 'La configuración venció. Inicia nuevamente.']);
            }
            $step = $this->step($user->mfa_pending_secret, $code, null);
            if ($step === false) {
                $this->invalidCode();
            }
            $codes = array_map(fn () => bin2hex(random_bytes(10)), range(1, 10));
            $this->identities->saveMfa($user, [
                'mfa_enabled' => true, 'mfa_secret' => $user->mfa_pending_secret,
                'mfa_pending_secret' => null, 'mfa_pending_expires_at' => null,
                'mfa_last_step' => $step, 'mfa_recovery_codes' => array_map(fn ($code) => Hash::make($code), $codes),
            ]);
            $this->identities->revokeAllTokens($user);
            Audit::record($id, 'mfa.enabled', (string) $id);

            return ['recovery_codes' => $codes, 'message' => 'Verificación activada. Guarda los códigos e inicia sesión nuevamente.'];
        });
    }

    public function disable(int $id, string $password, string $code): void
    {
        $this->guarded('mfa:'.$id, function () use ($id, $password, $code) {
            $user = $this->identities->findForUpdate($id);
            abort_if($this->policy->required($user), 403, 'Tu rol requiere mantener activa la verificación en dos pasos.');
            $this->checkPassword($user, $password);
            abort_unless($user->mfa_enabled, 409, 'La verificación en dos pasos no está activa.');
            $this->consume($user, $code);
            $this->identities->saveMfa($user, ['mfa_enabled' => false, 'mfa_secret' => null, 'mfa_pending_secret' => null, 'mfa_pending_expires_at' => null, 'mfa_recovery_codes' => null, 'mfa_last_step' => null]);
            $this->identities->revokeAllTokens($user);
            Audit::record($id, 'mfa.disabled', (string) $id);
        });
    }

    // Caller holds the user row lock until token issuance or MFA mutation commits.
    public function consume(User $user, ?string $code): void
    {
        if (! $code) {
            $exception = ValidationException::withMessages(['code' => 'Ingresa el código de tu aplicación o un código de recuperación.']);
            $exception->response = response()->json(['message' => $exception->getMessage(), 'errors' => $exception->errors(), 'mfa_required' => true], 422);
            throw $exception;
        }
        $step = $this->step($user->mfa_secret, $code, $user->mfa_last_step);
        if ($step !== false) {
            $this->identities->saveMfa($user, ['mfa_last_step' => $step]);

            return;
        }
        foreach ($user->mfa_recovery_codes ?? [] as $index => $hash) {
            if (Hash::check($code, $hash)) {
                $codes = $user->mfa_recovery_codes;
                unset($codes[$index]);
                $this->identities->saveMfa($user, ['mfa_recovery_codes' => array_values($codes)]);
                Audit::record($user->id, 'mfa.recovery.used', (string) $user->id);

                return;
            }
        }
        $this->invalidCode();
    }

    private function step(string $secret, string $code, ?int $previous): int|false
    {
        if (! preg_match('/^\d{6}$/D', $code)) {
            return false;
        }

        return $this->totp->verifyKeyNewer($secret, $code, $previous ?? 0, 1, intdiv(now()->timestamp, 30));
    }

    private function checkPassword(User $user, string $password): void
    {
        abort_unless($user->active, 403);
        if (! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'La contraseña actual no coincide.']);
        }
    }

    private function invalidCode(): never
    {
        throw ValidationException::withMessages(['code' => 'El código es inválido o ya fue utilizado. Espera un código nuevo o utiliza uno de recuperación.']);
    }
}
