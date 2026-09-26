<?php

namespace App\Application\Identity;

use App\Domain\Audit;
use App\Mail\PasswordResetMail;
use App\Repositories\Contracts\IdentityRepository;
use App\Repositories\Contracts\PasswordResetRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Throwable;

final class PasswordResetService
{
    public function __construct(private IdentityRepository $identities, private PasswordResetRepository $resets, private MfaService $mfa) {}

    /**
     * Respuesta idéntica exista o no la cuenta: el trabajo real (búsqueda, token, SES) ocurre
     * después de responder para no revelar cuentas ni por contenido ni por tiempo.
     */
    public function request(string $email): void
    {
        $email = $this->normalize($email);
        $key = 'reset-request:'.hash('sha256', $email);
        if (RateLimiter::tooManyAttempts($key, 3)) {
            return;
        }
        RateLimiter::hit($key, 900);
        defer(fn () => $this->send($email));
    }

    public function reset(string $email, string $token, string $password, ?string $code): void
    {
        $email = $this->normalize($email);
        $hash = hash('sha256', $email);
        try {
            $this->attempt($email, $hash, $token, $password, $code);
        } catch (ValidationException $failure) {
            // Fuera de la transacción (el contador debe sobrevivir al rollback): tras 5 códigos MFA
            // errados en 30 min el enlace se invalida, para que el buzón solo no permita adivinar códigos.
            if ($code !== null && $code !== '' && isset($failure->errors()['code'])) {
                $this->countMfaFailure($email, $hash);
            }
            throw $failure;
        }
        RateLimiter::clear('reset-mfa:'.$hash);
    }

    private function countMfaFailure(string $email, string $hash): void
    {
        $key = 'reset-mfa:'.$hash;
        RateLimiter::hit($key, 1800);
        if (RateLimiter::tooManyAttempts($key, 5)) {
            RateLimiter::clear($key);
            $this->identities->transaction(function () use ($email) {
                $this->resets->delete($email);
                Audit::record($this->identities->findByEmailForUpdate($email)?->id, 'password.reset.mfa_failed', hash('sha256', $email));
            });
        }
    }

    private function attempt(string $email, string $hash, string $token, string $password, ?string $code): void
    {
        $this->mfa->guarded('reset:'.$hash, function () use ($email, $token, $password, $code) {
            $user = $this->identities->findByEmailForUpdate($email);
            $row = $user ? $this->resets->findForUpdate($email) : null;
            $minutes = (int) config('security.password_reset_minutes');
            $valid = $row
                && hash_equals($row['token'], hash('sha256', $token))
                && Carbon::parse($row['created_at'], 'UTC')->addMinutes($minutes)->isFuture();
            if (! $valid || ! $user->active) {
                throw ValidationException::withMessages(['token' => 'El enlace no es válido o venció. Solicita uno nuevo.']);
            }
            // El correo solo no basta: con MFA activo se exige también el código (o uno de recuperación).
            if ($user->mfa_enabled) {
                $this->mfa->consume($user, $code);
            }
            $this->identities->updatePassword($user, $password);
            $this->resets->delete($email);
            $this->identities->revokeAllTokens($user);
            Audit::record($user->id, 'password.reset', (string) $user->id);
        });
    }

    private function send(string $email): void
    {
        try {
            if (app()->isProduction() && config('mail.default') === 'log') {
                // El driver log escribiría el enlace (con el token) en laravel.log.
                Log::warning('Recuperación de contraseña: MAIL_MAILER=log en producción; no se envía.');

                return;
            }
            $base = (string) config('security.frontend_url');
            $minutes = (int) config('security.password_reset_minutes');
            $token = bin2hex(random_bytes(32));
            $user = $this->identities->transaction(function () use ($email, $token) {
                $user = $this->identities->findByEmailForUpdate($email);
                if (! $user || ! $user->active) {
                    return null;
                }
                $this->resets->store($email, hash('sha256', $token), now()->utc());
                Audit::record($user->id, 'password.reset.requested', (string) $user->id);

                return $user;
            });
            if (! $user) {
                return;
            }
            if ($base === '') {
                Log::warning('Recuperación de contraseña: FRONTEND_URL sin configurar.', ['user_id' => $user->id]);

                return;
            }
            // Fragmento (#): el token no viaja al servidor web, a registros ni en Referer.
            $url = $base.'/restablecer#'.http_build_query(['token' => $token, 'email' => $email]);
            Mail::to($user->email)->send(new PasswordResetMail($user->name, $url, $minutes));
        } catch (Throwable $failure) {
            // Sin correo, token ni URL en el registro.
            Log::warning('Recuperación de contraseña: no se pudo enviar.', ['error' => $failure::class]);
        }
    }

    private function normalize(string $email): string
    {
        return strtolower(trim($email));
    }
}
