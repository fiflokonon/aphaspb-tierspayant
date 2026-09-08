<?php

namespace Database\Factories;

use App\Models\Declaration;
use App\Models\DeclarationPayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeclarationPayment>
 */
class DeclarationPaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'declaration_id' => Declaration::factory(),
            'amount' => fake()->numberBetween(1, 20) * 50_000,
            'paid_on' => fake()->dateTimeBetween('-6 months')->format('Y-m-d'),
            'delay_days' => fake()->numberBetween(8, 95),
        ];
    }
}
