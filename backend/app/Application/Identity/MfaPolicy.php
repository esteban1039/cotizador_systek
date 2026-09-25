<?php

namespace App\Application\Identity;

use App\Models\User;

final class MfaPolicy
{
    public function required(User $user): bool
    {
        return in_array($user->role, config('security.mfa_required_roles', []), true);
    }

    public function enrollmentRequired(User $user): bool
    {
        return $this->required($user) && ! $user->mfa_enabled;
    }

    public function userData(User $user): array
    {
        return array_merge($user->toArray(), [
            'mfa_required' => $this->required($user),
            'mfa_enrollment_required' => $this->enrollmentRequired($user),
        ]);
    }
}
