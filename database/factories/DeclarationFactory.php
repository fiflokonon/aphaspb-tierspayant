<?php

namespace Database\Factories;

use App\Actions\Declarations\RecordPaymentInstalments;
use App\Enums\DeclarationStatus;
use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\Pharmacy;
use Carbon\CarbonImmutable;
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
     * Turn the requested delay back into the pair of dates that produces it.
     *
     * Tests and the demo seeder describe a declaration by its delay, which is
     * the readable unit; the model derives that delay from the deposit and
     * payment dates. Rather than make every caller compute a pair, the factory
     * anchors one on the declared month — unless the caller gave dates itself.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (Declaration $declaration) {
            if ($declaration->invoice_deposited_on !== null) {
                return;
            }

            $deposited = CarbonImmutable::create(
                $declaration->period_year,
                $declaration->period_month,
                1,
            )->endOfMonth()->startOfDay();

            $declaration->invoice_deposited_on = $deposited;
            $declaration->paid_on = $declaration->delay_days === null
                ? null
                : $deposited->addDays($declaration->delay_days);
        })->afterCreating(function (Declaration $declaration) {
            // Un règlement encaissé se lit désormais dans `declaration_payments`
            // — la colonne `amount_received` n'en est que le cache. Une
            // déclaration fabriquée sans versement laisserait donc les deux en
            // désaccord, et tout agrégat par versement la verrait vide.
            //
            // Le cas courant tient en une ligne, ce que produisait la version
            // à un seul paiement. Les échéances multiples se demandent
            // explicitement, via instalments().
            if ($declaration->amount_received <= 0 || $declaration->paid_on === null) {
                return;
            }

            if ($declaration->payments()->exists()) {
                return;
            }

            $declaration->payments()->create([
                'amount' => $declaration->amount_received,
                'paid_on' => $declaration->paid_on,
                'delay_days' => $declaration->delay_days,
            ]);
        });
    }

    /**
     * Settled in several transfers, each with its own date.
     *
     * The declaration's own total and payment date are then derived from the
     * lines, exactly as the declaration screen derives them.
     *
     * @param  list<array{amount: int, paid_on: string}>  $instalments
     */
    public function instalments(array $instalments): static
    {
        return $this->afterCreating(function (Declaration $declaration) use ($instalments) {
            app(RecordPaymentInstalments::class)->handle($declaration, $instalments);
        });
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
