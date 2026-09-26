<?php

namespace App\Repositories\Contracts;

use DateTimeInterface;

interface PasswordResetRepository
{
    /** Guarda el hash del enlace; reemplaza (e invalida) el anterior del mismo correo. */
    public function store(string $email, string $tokenHash, DateTimeInterface $createdAt): void;

    /** @return array{token: string, created_at: string}|null */
    public function findForUpdate(string $email): ?array;

    public function delete(string $email): void;
}
