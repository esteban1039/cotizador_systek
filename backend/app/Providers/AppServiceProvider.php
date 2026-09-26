<?php

namespace App\Providers;

use App\Application\Drive\DriveInventoryClient;
use App\Infrastructure\Drive\GoogleDriveInventoryClient;
use App\Repositories\Contracts\ClauseRepository;
use App\Repositories\Contracts\CompanyRepository;
use App\Repositories\Contracts\DashboardRepository;
use App\Repositories\Contracts\HistoryRepository;
use App\Repositories\Contracts\PricingRepository;
use App\Repositories\Contracts\QuoteEmissionRepository;
use App\Repositories\Contracts\QuoteFollowupRepository;
use App\Repositories\Contracts\QuoteNumberRepository;
use App\Repositories\Contracts\QuoteRepository;
use App\Repositories\Eloquent\EloquentClauseRepository;
use App\Repositories\Eloquent\EloquentCompanyRepository;
use App\Repositories\Eloquent\EloquentDashboardRepository;
use App\Repositories\Eloquent\EloquentHistoryRepository;
use App\Repositories\Eloquent\EloquentPricingRepository;
use App\Repositories\Eloquent\EloquentQuoteEmissionRepository;
use App\Repositories\Eloquent\EloquentQuoteFollowupRepository;
use App\Repositories\Eloquent\EloquentQuoteNumberRepository;
use App\Repositories\Eloquent\EloquentQuoteRepository;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(DriveInventoryClient::class, GoogleDriveInventoryClient::class);
        $this->app->bind(HistoryRepository::class, EloquentHistoryRepository::class);
        $this->app->bind(DashboardRepository::class, EloquentDashboardRepository::class);
        $this->app->bind(QuoteRepository::class, EloquentQuoteRepository::class);
        $this->app->bind(PricingRepository::class, EloquentPricingRepository::class);
        $this->app->bind(CompanyRepository::class, EloquentCompanyRepository::class);
        $this->app->bind(ClauseRepository::class, EloquentClauseRepository::class);
        $this->app->bind(QuoteNumberRepository::class, EloquentQuoteNumberRepository::class);
        $this->app->bind(QuoteEmissionRepository::class, EloquentQuoteEmissionRepository::class);
        $this->app->bind(QuoteFollowupRepository::class, EloquentQuoteFollowupRepository::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
