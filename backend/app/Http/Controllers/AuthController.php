<?php

namespace App\Http\Controllers;

use App\Application\Identity\MfaPolicy;
use App\Application\Identity\MfaService;
use App\Domain\Audit;
use App\Repositories\Contracts\IdentityRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

final class AuthController extends Controller
{
    public function __construct(private IdentityRepository $identities, private MfaService $mfa, private MfaPolicy $policy) {}

    public function login(Request $request): JsonResponse
    {
        $input = $request->validate(['email' => ['required', 'email', 'max:255'], 'password' => ['required', 'string', 'max:256'], 'code' => ['nullable', 'string', 'max:64']]);
        $email = strtolower(trim($input['email']));
        $key = 'login:'.hash('sha256', $email);
        if (RateLimiter::tooManyAttempts($key, 5)) {
            abort(429, 'Demasiados intentos. Intenta nuevamente en un minuto.');
        }

        return $this->mfa->guarded($key, function () use ($email, $input) {
            // Share the user row lock with password and access changes before issuing a token.
            $user = $this->identities->findByEmailForUpdate($email);
            $valid = Hash::check($input['password'], $user?->password ?? '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
            if (! $user || ! $valid || ! $user->active) {
                throw ValidationException::withMessages(['email' => 'Correo o contraseña incorrectos.']);
            }
            if ($user->mfa_enabled) {
                $this->mfa->consume($user, $input['code'] ?? null);
            }
            $token = $this->identities->issueToken($user, now()->addHours(8));
            Audit::record($user->id, 'session.created', (string) $user->id);

            return response()->json(['user' => $this->policy->userData($user), 'token' => $token]);
        });
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->policy->userData($request->user())]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->identities->revokeCurrentToken($request->user());
        Audit::record($request->user()->id, 'session.revoked', (string) $request->user()->id);

        return response()->json(['message' => 'Sesión cerrada.']);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $input = $request->validate([
            'current_password' => ['required', 'string', 'max:256'],
            'password' => ['required', 'string', 'min:12', 'max:128', 'confirmed'],
        ]);
        $this->identities->transaction(function () use ($request, $input) {
            $user = $this->identities->findForUpdate($request->user()->id);
            abort_unless($user->active, 403);
            if (! Hash::check($input['current_password'], $user->password)) {
                throw ValidationException::withMessages(['current_password' => 'La contraseña actual no coincide.']);
            }
            $this->identities->updatePassword($user, $input['password']);
            $this->identities->revokeAllTokens($user);
            Audit::record($user->id, 'password.changed', (string) $user->id);
        });

        return response()->json(['message' => 'Contraseña cambiada. Inicia sesión nuevamente.']);
    }
}
