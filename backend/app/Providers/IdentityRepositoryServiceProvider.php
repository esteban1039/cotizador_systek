<?php

namespace App\Providers;

use App\Repositories\Contracts\AuditRepository;
use App\Repositories\Contracts\IdentityRepository;
use App\Repositories\Eloquent\EloquentAuditRepository;
use App\Repositories\Eloquent\EloquentIdentityRepository;
use Illuminate\Support\ServiceProvider;

final class IdentityRepositoryServiceProvider extends ServiceProvider
{
    public array $bindings = [
        IdentityRepository::class => EloquentIdentityRepository::class,
        AuditRepository::class => EloquentAuditRepository::class,
    ];
}
