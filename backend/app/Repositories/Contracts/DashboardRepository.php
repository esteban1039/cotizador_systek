<?php

namespace App\Repositories\Contracts;

use App\Models\User;

interface DashboardRepository
{
    public function summary(User $user, string $asOf): array;
}
