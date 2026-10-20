<?php

namespace Database\Factories;

use App\Enums\EvaluationCriterion;
use App\Models\EvaluationCampaign;
use App\Models\Insurer;
use App\Models\InsurerEvaluation;
use App\Models\Pharmacy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InsurerEvaluation>
 */
class InsurerEvaluationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $scores = [];

        foreach (EvaluationCriterion::cases() as $criterion) {
            $scores[$criterion->value] = fake()->numberBetween(0, $criterion->maxPoints());
        }

        return [
            'evaluation_campaign_id' => EvaluationCampaign::factory(),
            'pharmacy_id' => Pharmacy::factory(),
            'insurer_id' => Insurer::factory(),
            'author_name' => fake()->name(),
            ...$scores,
        ];
    }

    /**
     * Every criterion at the same share of its maximum, for readable averages.
     */
    public function scoring(float $share): static
    {
        return $this->state(function () use ($share) {
            $scores = [];

            foreach (EvaluationCriterion::cases() as $criterion) {
                $scores[$criterion->value] = (int) round($criterion->maxPoints() * $share);
            }

            return $scores;
        });
    }
}
