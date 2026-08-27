<?php

namespace Database\Factories;

use App\Models\Pharmacy;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Pharmacy>
 */
class PharmacyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = 'Pharmacie '.fake()->unique()->lastName();

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'onpb_license' => fake()->boolean(70) ? 'ONPB-'.fake()->unique()->numberBetween(1000, 9999) : null,
            'city' => fake()->randomElement(['Cotonou', 'Porto-Novo', 'Parakou', 'Abomey-Calavi', 'Bohicon']),
            'owner_name' => fake()->name(),
        ];
    }

    /**
     * Indicate that the pharmacy has been deleted.
     */
    public function trashed(): static
    {
        return $this->state(fn (array $attributes) => [
            'deleted_at' => now(),
        ]);
    }
}
