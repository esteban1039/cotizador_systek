<?php

namespace Database\Factories;

use App\Models\QuoteKnowledge;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<QuoteKnowledge>
 */
class QuoteKnowledgeFactory extends Factory
{
    protected $model = QuoteKnowledge::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'source' => 'drive_import',
            'source_ref' => 'REF-'.Str::upper(Str::random(8)),
            'family' => 'cctv',
            'requirement_text' => 'Camara IP exterior con instalacion',
            'lines' => [['sku' => null, 'description' => 'Camara IP exterior', 'unit' => 'unidad', 'quantity' => null, 'family' => 'cctv']],
            'lines_text' => 'Camara IP exterior',
            'status' => 'active',
            'trust' => '0.50',
            'scrub_flags' => [],
            'captured_at' => now(),
        ];
    }
}
