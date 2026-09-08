<?php

namespace Database\Factories;

use App\Enums\DeclarationStatus;
use App\Models\Declaration;
use App\Models\DeclarationRevision;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeclarationRevision>
 */
class DeclarationRevisionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $user = User::factory();

        return [
            'declaration_id' => Declaration::factory(),
            'user_id' => $user,
            'author_name' => fake()->name(),
            'amount_invoiced' => 1_000_000,
            'amount_received' => 1_000_000,
            'status' => DeclarationStatus::Paid,
            'invoice_deposited_on' => '2026-08-01',
            'paid_on' => '2026-08-20',
            'delay_days' => 19,
            'payments' => [
                ['amount' => 1_000_000, 'paid_on' => '2026-08-20', 'delay_days' => 19],
            ],
        ];
    }
}
