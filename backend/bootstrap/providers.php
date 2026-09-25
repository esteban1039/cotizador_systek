<?php

use App\Providers\AppServiceProvider;
use App\Providers\ClientCatalogRepositoryServiceProvider;
use App\Providers\IdentityRepositoryServiceProvider;

return [
    AppServiceProvider::class,
    ClientCatalogRepositoryServiceProvider::class,
    IdentityRepositoryServiceProvider::class,
];
