<?php

namespace Tests\Feature;

use App\Models\Client;
use Database\Seeders\ClientSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ClientSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_crea_clientes_es_idempotente_y_no_sobrescribe(): void
    {
        $this->seed(ClientSeeder::class);
        $first = Client::query()->count();
        $this->assertGreaterThan(10, $first);
        $this->assertSame(0, Client::query()->where('is_demo', true)->count());

        $existing = Client::query()->whereNotNull('nit')->firstOrFail();
        $existing->update(['name' => 'Nombre editado por admin']);

        $this->seed(ClientSeeder::class);
        $this->assertSame($first, Client::query()->count());
        $this->assertSame('Nombre editado por admin', $existing->fresh()->name);
    }
}
