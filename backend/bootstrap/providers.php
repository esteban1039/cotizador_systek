<?php

use App\Providers\AppServiceProvider;
use App\Providers\ClientCatalogRepositoryServiceProvider;
use App\Providers\IdentityRepositoryServiceProvider;
use App\Providers\KnowledgeRepositoryServiceProvider;

return [
    AppServiceProvider::class,
    ClientCatalogRepositoryServiceProvider::class,
    IdentityRepositoryServiceProvider::class,
    KnowledgeRepositoryServiceProvider::class,
];
