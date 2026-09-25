<?php

namespace Database\Factories;

use App\Models\Clause;
use App\Models\ClauseVersion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ClauseVersion>
 */
class ClauseVersionFactory extends Factory
{
    protected $model = ClauseVersion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $body = fake()->paragraph();

        return [
            'id' => (string) Str::uuid(),
            'clause_id' => Clause::factory(),
            'version' => 1,
            'status' => 'current',
            'body' => $body,
            // Hash independiente de App\Domain\Quotes\ClauseText (dominio de otra
            // capa/agente): normaliza igual (trim, colapsa espacios, minúsculas).
            'body_hash' => hash('sha256', mb_strtolower(trim((string) preg_replace('/\s+/', ' ', $body)))),
            'origin' => 'admin',
            'reason' => 'Datos de prueba generados por la factory',
            'published_by' => null,
        ];
    }

    public function historical(): static
    {
        return $this->state(fn (): array => ['status' => 'historical']);
    }
}
