<?php

namespace App\Repositories\Eloquent;

use App\Repositories\Contracts\PasswordResetRepository;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

final class EloquentPasswordResetRepository implements PasswordResetRepository
{
    public function store(string $email, string $tokenHash, DateTimeInterface $createdAt): void
    {
        DB::table('password_reset_tokens')->upsert([['email' => $email, 'token' => $tokenHash, 'created_at' => $createdAt]], ['email'], ['token', 'created_at']);
    }

    public function findForUpdate(string $email): ?array
    {
        $row = DB::table('password_reset_tokens')->where('email', $email)->lockForUpdate()->first();

        return $row ? ['token' => $row->token, 'created_at' => (string) $row->created_at] : null;
    }

    public function delete(string $email): void
    {
        DB::table('password_reset_tokens')->where('email', $email)->delete();
    }
}
