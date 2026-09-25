<?php

namespace App\Repositories\Contracts;

use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Collection;

interface IdentityRepository
{
    public function transaction(callable $callback): mixed;

    public function allByName(): Collection;

    public function find(int $id): ?User;

    public function findForUpdate(int $id): User;

    public function findByEmailForUpdate(string $email): ?User;

    public function lockAdministrators(): void;

    public function hasAdministrator(): bool;

    public function emailExists(string $email): bool;

    public function create(string $name, string $email, string $password, string $role): User;

    public function updateAccess(User $user, string $role, bool $active): void;

    public function saveMfa(User $user, array $attributes): void;

    public function updatePassword(User $user, string $password): void;

    public function issueToken(User $user, DateTimeInterface $expiresAt): string;

    public function revokeCurrentToken(User $user): void;

    public function revokeAllTokens(User $user): void;
}
