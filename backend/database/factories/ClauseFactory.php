<?php

namespace Database\Factories;

use App\Models\Clause;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Clause>
 */
class ClauseFactory extends Factory
{
    protected $model = Clause::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'family' => 'cctv',
            'type' => 'payment',
            'title' => fake()->unique()->sentence(3),
            'is_default' => false,
            'active' => true,
            'is_demo' => false,
            'created_by' => null,
        ];
    }

    public function default(): static
    {
        return $this->state(fn (): array => ['is_default' => true]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['active' => false]);
    }
}
