<?php

namespace App\Providers;

use App\Repositories\Contracts\KnowledgeRepository;
use App\Repositories\Eloquent\EloquentKnowledgeRepository;
use Illuminate\Support\ServiceProvider;

final class KnowledgeRepositoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(KnowledgeRepository::class, EloquentKnowledgeRepository::class);
    }
}
