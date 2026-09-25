<?php

namespace App\Providers;

use App\Repositories\Contracts\CatalogRepository;
use App\Repositories\Contracts\ClientRepository;
use App\Repositories\Eloquent\EloquentCatalogRepository;
use App\Repositories\Eloquent\EloquentClientRepository;
use Illuminate\Support\ServiceProvider;

final class ClientCatalogRepositoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ClientRepository::class, EloquentClientRepository::class);
        $this->app->bind(CatalogRepository::class, EloquentCatalogRepository::class);
    }
}
