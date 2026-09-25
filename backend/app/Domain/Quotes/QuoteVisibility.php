<?php

namespace App\Domain\Quotes;

use App\Models\User;

final class QuoteVisibility
{
    public static function redact(array $snapshot, User $user): array
    {
        if ($user->role === 'quoter') {
            unset($snapshot['totals']['cost'], $snapshot['profit']);
            foreach ($snapshot['lines'] as &$line) {
                unset($line['cost_cents'], $line['amounts']['cost']);
            }
            unset($line);
        }

        return $snapshot;
    }
}
