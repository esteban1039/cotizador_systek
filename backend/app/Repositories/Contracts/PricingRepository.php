<?php

namespace App\Repositories\Contracts;

interface PricingRepository
{
    /** Caller must hold a transaction; locks item before price to match publication. */
    public function lockedPrice(string $id): array;

    public function lockedRule(string $family): ?object;
}
