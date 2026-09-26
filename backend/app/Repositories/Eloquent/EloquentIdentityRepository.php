<?php

namespace App\Repositories\Eloquent;

use App\Models\User;
use App\Repositories\Contracts\IdentityRepository;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class EloquentIdentityRepository implements IdentityRepository
{
    public function transaction(callable $callback): mixed
    {
        return DB::transaction($callback);
    }

    public function allByName(): Collection
    {
        return User::orderBy('name')->get();
    }

    public function find(int $id): ?User
    {
        return User::find($id);
    }

    public function findForUpdate(int $id): User
    {
        return User::whereKey($id)->lockForUpdate()->firstOrFail();
    }

    public function findByEmailForUpdate(string $email): ?User
    {
        return User::where('email', $email)->lockForUpdate()->first();
    }

    public function lockAdministrators(): void
    {
        User::where('role', 'admin')->orderBy('id')->lockForUpdate()->get();
    }

    public function hasAdministrator(): bool
    {
        return User::where('role', 'admin')->exists();
    }

    public function emailExists(string $email): bool
    {
        return User::where('email', $email)->exists();
    }

    public function create(string $name, string $email, string $password, string $role): User
    {
        $user = new User(compact('name', 'email', 'password'));
        $user->role = $role;
        $user->save();

        return $user;
    }

    public function updateAccess(User $user, string $role, bool $active): void
    {
        $user->mfa_pending_secret = null;
        $user->mfa_pending_expires_at = null;
        $user->role = $role;
        $user->active = $active;
        $user->save();
        $this->discardResetLink($user);
    }

    public function saveMfa(User $user, array $attributes): void
    {
        $user->forceFill($attributes)->save();
    }

    public function updatePassword(User $user, string $password): void
    {
        $user->mfa_pending_secret = null;
        $user->mfa_pending_expires_at = null;
        $user->password = $password;
        $user->save();
        $this->discardResetLink($user);
    }

    public function issueToken(User $user, DateTimeInterface $expiresAt): string
    {
        return $user->createToken('editor', ['*'], $expiresAt)->plainTextToken;
    }

    public function revokeCurrentToken(User $user): void
    {
        $user->currentAccessToken()->delete();
    }

    public function revokeAllTokens(User $user): void
    {
        $user->tokens()->delete();
    }

    /** Un cambio de contraseña o de acceso deja sin efecto cualquier enlace de recuperación ya emitido. */
    private function discardResetLink(User $user): void
    {
        DB::table('password_reset_tokens')->where('email', $user->email)->delete();
    }
}
