<?php

namespace Database\Factories;

use App\Models\Insurer;
use App\Models\NilDeclaration;
use App\Models\Pharmacy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NilDeclaration>
 */
class NilDeclarationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'pharmacy_id' => Pharmacy::factory(),
            'insurer_id' => Insurer::factory(),
            'period_year' => (int) now()->subMonth()->year,
            'period_month' => (int) now()->subMonth()->month,
            'author_name' => fake()->name(),
        ];
    }
}
