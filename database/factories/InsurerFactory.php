<?php

namespace Database\Factories;

use App\Models\Insurer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Insurer>
 */
class InsurerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company().' Assurances',
            'is_active' => true,
        ];
    }

    /**
     * A penalty clause, as a convention would spell it out.
     *
     * Takes a percentage because that is what a convention says; the column
     * holds basis points, and the conversion belongs here rather than in every
     * test that needs a clause.
     */
    public function withPenalty(int $triggerDays = 60, float $ratePercent = 2.0): static
    {
        return $this->state(fn (array $attributes) => [
            'penalty_trigger_days' => $triggerDays,
            'penalty_rate_bp' => (int) round($ratePercent * 100),
        ]);
    }

    /**
     * Indicate that the insurer no longer appears in the forms.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
