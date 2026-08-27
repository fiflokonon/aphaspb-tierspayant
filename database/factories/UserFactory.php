<?php

namespace Database\Factories;

use App\Enums\PharmacyRole;
use App\Models\Insurer;
use App\Models\Pharmacy;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'joomla_user_id' => fake()->unique()->numberBetween(1_000, 999_999),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'joomla_groups' => config('joomla.groups.pharmacy') ?: [2],
            'token_version' => 0,
        ];
    }

    /**
     * Indicate that the user belongs to the APhaSPB admin group.
     */
    public function networkAdmin(): static
    {
        return $this->state(fn (array $attributes) => [
            'joomla_groups' => config('joomla.groups.admin') ?: [8],
        ]);
    }

    /**
     * Configure the model factory.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (User $user) {
            $pharmacy = Pharmacy::factory()->create();

            $pharmacy->members()->attach($user, [
                'role' => PharmacyRole::Owner->value,
            ]);

            // A ticked insurer, so the default user is fully onboarded: most
            // tests want a working officine, and declaring is impossible
            // without one. Use notOnboarded() for the opposite case.
            $pharmacy->insurers()->attach(Insurer::factory()->create());

            $user->switchPharmacy($pharmacy);
        });
    }

    /**
     * Indicate a user as the Joomla callback leaves them: no officine at all.
     */
    public function notOnboarded(): static
    {
        return $this->afterCreating(function (User $user) {
            $user->pharmacies->each->forceDelete();
            $user->pharmacies()->detach();
            $user->update(['current_pharmacy_id' => null]);

            // switchPharmacy() cached the relation in memory; clearing the row
            // is not enough for the instance the factory hands back.
            $user->unsetRelation('currentPharmacy')->unsetRelation('pharmacies');
        });
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
