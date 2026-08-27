<?php

namespace Database\Factories;

use App\Enums\DeclarationStatus;
use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\Pharmacy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Declaration>
 */
class DeclarationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $invoiced = fake()->numberBetween(2, 40) * 50_000;

        return [
            'pharmacy_id' => Pharmacy::factory(),
            'insurer_id' => Insurer::factory(),
            'period_year' => 2026,
            'period_month' => fake()->numberBetween(1, 12),
            'amount_invoiced' => $invoiced,
            'amount_received' => $invoiced,
            'status' => DeclarationStatus::Paid,
            'is_status_manual' => false,
            'delay_days' => fake()->numberBetween(8, 95),
            'private_note' => null,
        ];
    }

    /**
     * Fully paid — the status the default state already produces.
     */
    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'amount_received' => $attributes['amount_invoiced'],
        ]);
    }

    /**
     * Partly paid, so a share remains outstanding.
     */
    public function partial(): static
    {
        return $this->state(fn (array $attributes) => [
            'amount_received' => (int) ($attributes['amount_invoiced'] * 0.6),
        ]);
    }

    /**
     * Nothing received, so no delay to record either.
     */
    public function unpaid(): static
    {
        return $this->state(fn (array $attributes) => [
            'amount_received' => 0,
            'delay_days' => null,
        ]);
    }

    /**
     * Refused by the insurer — always an explicit choice, never derived.
     */
    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'amount_received' => 0,
            'delay_days' => null,
            'status' => DeclarationStatus::Rejected,
            'is_status_manual' => true,
        ]);
    }
}
