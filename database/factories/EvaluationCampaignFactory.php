<?php

namespace Database\Factories;

use App\Models\EvaluationCampaign;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EvaluationCampaign>
 */
class EvaluationCampaignFactory extends Factory
{
    /**
     * Define the model's default state: open today, on last year.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'year' => (int) now()->subYear()->year,
            'opens_on' => today()->subWeek()->toDateString(),
            'closes_on' => today()->addWeeks(3)->toDateString(),
        ];
    }

    /**
     * Closed yesterday: its results may be published.
     */
    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'opens_on' => today()->subMonth()->toDateString(),
            'closes_on' => today()->subDay()->toDateString(),
        ]);
    }

    /**
     * Not open yet.
     */
    public function upcoming(): static
    {
        return $this->state(fn (array $attributes) => [
            'opens_on' => today()->addWeek()->toDateString(),
            'closes_on' => today()->addMonth()->toDateString(),
        ]);
    }
}
