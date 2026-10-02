<?php

namespace Tests\Feature;

use App\Models\QuoteKnowledge;
use Database\Seeders\KnowledgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class KnowledgeSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_puebla_la_base_sin_administrador_y_es_idempotente(): void
    {
        $this->seed(KnowledgeSeeder::class);
        $first = QuoteKnowledge::query()->where('source', 'drive_import')->count();

        $this->assertGreaterThan(100, $first);
        $this->assertSame(0, QuoteKnowledge::query()->whereNotNull('source_root_quote_id')->count());

        $this->seed(KnowledgeSeeder::class);
        $this->assertSame($first, QuoteKnowledge::query()->where('source', 'drive_import')->count());
    }
}
