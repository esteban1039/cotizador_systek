<?php

namespace Tests\Feature;

use App\Domain\Quotes\QuotePricer;
use App\Repositories\Contracts\PricingRepository;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

final class RepositoryArchitectureTest extends TestCase
{
    public function test_pricing_uses_the_injected_repository_without_database_access(): void
    {
        $repository = Mockery::mock(PricingRepository::class);
        $repository->shouldReceive('lockedPrice')->once()->with('missing-price')->andReturn([null, null]);
        $this->app->instance(PricingRepository::class, $repository);

        $this->expectException(ValidationException::class);
        $this->app->make(QuotePricer::class)->calculate([['price_version_id' => 'missing-price']]);
    }

    public function test_all_repository_contracts_are_bound_to_concrete_implementations(): void
    {
        foreach (glob(app_path('Repositories/Contracts/*Repository.php')) as $file) {
            $contract = 'App\\Repositories\\Contracts\\'.basename($file, '.php');
            $this->assertInstanceOf($contract, $this->app->make($contract));
        }
    }

    public function test_controllers_and_quote_domain_do_not_build_database_queries(): void
    {
        foreach (array_merge(glob(app_path('Http/Controllers/*.php')), glob(app_path('Domain/Quotes/*.php')), glob(app_path('Application/Quotes/*.php')), glob(app_path('Application/Company/*.php'))) as $file) {
            $source = file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression('/DB::(?:table|select|insert|update|delete|statement)\s*\(|::(?:query|where|whereKey|find|orderBy)\s*\(/', $source, $file);
        }
    }
}
