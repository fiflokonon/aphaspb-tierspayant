<?php

namespace Database\Seeders;

use App\Enums\DeclarationStatus;
use App\Enums\PharmacyRole;
use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\Pharmacy;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * A believable network to look at the screens with.
 *
 * Two things it deliberately arranges, because both are part of the design and
 * neither shows up with naive random data:
 *
 *  - most insurers clear the five-officine anonymity threshold, so the admin
 *    screens have figures to show at all;
 *  - two insurers stay below it, so the « données insuffisantes » state is
 *    visible too — it is an interface state, not an error.
 *
 * Delays are shaped per insurer rather than drawn at random, so the twelve-month
 * curve tells the story the canvas tells: one insurer steady under the
 * threshold, one drifting badly upward.
 */
class DemoSeeder extends Seeder
{
    protected const PHARMACIES = 30;

    protected const MONTHS = 12;

    protected const HERO_SLUG = 'pharmacie-le-bon-secours';

    /** Insurers the demo officine has not declared yet this month. */
    protected const LEFT_TO_DECLARE = [
        "L'Africaine des Assurances",
        'Courtier — Ascoma Bénin',
    ];

    /** Cities weighted the way Benin's officines actually distribute. */
    protected const CITIES = [
        'Cotonou', 'Cotonou', 'Cotonou', 'Cotonou',
        'Abomey-Calavi', 'Abomey-Calavi',
        'Porto-Novo', 'Porto-Novo',
        'Parakou', 'Bohicon', 'Natitingou', 'Djougou',
    ];

    /**
     * Per insurer: the delay agreed with the APhaSPB, the delay actually
     * observed, its monthly drift, the rejection rate, and how many officines
     * declare to it. The last figure is what decides whether the insurer clears
     * the anonymity threshold; the gap between « standard » and « delay » is
     * what the screens are there to show.
     *
     * @var array<string, array{standard: int, delay: int, drift: float, rejection: float, pharmacies: int}>
     */
    protected const PROFILES = [
        'NSIA Assurances' => ['standard' => 30, 'delay' => 26, 'drift' => 0.2, 'rejection' => 0.04, 'pharmacies' => 24],
        'SUNU Assurances' => ['standard' => 30, 'delay' => 38, 'drift' => 0.6, 'rejection' => 0.08, 'pharmacies' => 21],
        "L'Africaine des Assurances" => ['standard' => 45, 'delay' => 58, 'drift' => 1.8, 'rejection' => 0.19, 'pharmacies' => 16],
        'Sanlam Assurances' => ['standard' => 30, 'delay' => 47, 'drift' => 0.4, 'rejection' => 0.06, 'pharmacies' => 11],
        'Atlantique Assurances' => ['standard' => 60, 'delay' => 40, 'drift' => 0.3, 'rejection' => 0.05, 'pharmacies' => 3],
        'Courtier — Ascoma Bénin' => ['standard' => 30, 'delay' => 35, 'drift' => 0.1, 'rejection' => 0.03, 'pharmacies' => 1],
    ];

    public function run(): void
    {
        $this->call(InsurerSeeder::class);

        $insurers = Insurer::query()->get()->keyBy('name');
        $pharmacies = $this->pharmacies();

        $this->command->info('Déclarations sur '.self::MONTHS.' mois…');

        foreach (self::PROFILES as $name => $profile) {
            $insurer = $insurers->get($name);

            if ($insurer === null) {
                continue;
            }

            $insurer->update(['standard_delay_days' => $profile['standard']]);

            $declaring = $pharmacies->take($profile['pharmacies']);

            foreach ($declaring as $pharmacy) {
                $pharmacy->insurers()->syncWithoutDetaching($insurer);

                // The demo officine leaves its two slowest insurers undeclared
                // for the current month, so /pharmacy/declare opens on the
                // wizard rather than the done screen — the canvas's 5/7 state.
                $leaveOpen = $pharmacy->slug === self::HERO_SLUG
                    && in_array($name, self::LEFT_TO_DECLARE, true);

                $this->declareTwelveMonths($pharmacy, $insurer, $profile, $leaveOpen);
            }
        }

        $this->admin();

        $this->command->info('Fait. Connexion locale : /dev/login/officine ou /dev/login/admin');
    }

    /**
     * The demo officine first, so it is the one the local login lands on.
     *
     * @return Collection<int, Pharmacy>
     */
    protected function pharmacies()
    {
        $hero = Pharmacy::query()->firstOrCreate(
            ['slug' => self::HERO_SLUG],
            [
                'name' => 'Pharmacie Le Bon Secours',
                'onpb_license' => 'ONPB-4212',
                'city' => 'Cotonou',
                'owner_name' => 'Awa Hounkpatin',
            ],
        );

        $this->titulaire($hero, 'titulaire@bonsecours.local', 'Awa Hounkpatin');

        $others = collect(range(2, self::PHARMACIES))->map(function (int $index) {
            $pharmacy = Pharmacy::factory()->create([
                'city' => self::CITIES[$index % count(self::CITIES)],
            ]);

            $this->titulaire($pharmacy, "titulaire{$index}@officine.local", $pharmacy->owner_name);

            return $pharmacy;
        });

        return collect([$hero])->concat($others);
    }

    /**
     * Give an officine an owner in the pharmacy Joomla group.
     */
    protected function titulaire(Pharmacy $pharmacy, string $email, string $name): void
    {
        $user = User::query()->firstOrCreate(['email' => $email], [
            'joomla_user_id' => random_int(10_000, 999_999),
            'name' => $name,
            'email_verified_at' => now(),
            'joomla_groups' => config('joomla.groups.pharmacy') ?: [2],
            'token_version' => 0,
        ]);

        $pharmacy->members()->syncWithoutDetaching([
            $user->id => ['role' => PharmacyRole::Owner->value],
        ]);

        if ($user->current_pharmacy_id === null) {
            $user->switchPharmacy($pharmacy);
        }
    }

    /**
     * Twelve months of declarations shaped by the insurer's profile.
     *
     * @param  array{standard: int, delay: int, drift: float, rejection: float, pharmacies: int}  $profile
     */
    protected function declareTwelveMonths(
        Pharmacy $pharmacy,
        Insurer $insurer,
        array $profile,
        bool $leaveCurrentMonthOpen = false,
    ): void {
        $earliest = $leaveCurrentMonthOpen ? 1 : 0;

        for ($back = self::MONTHS - 1; $back >= $earliest; $back--) {
            $month = now()->subMonths($back);
            // Scaled to the canvas: about 56 M FCFA invoiced over twelve
            // months across six insurers, not three times that.
            $invoiced = random_int(4, 28) * 50_000;

            // The drift makes the twelve-month curve rise for the insurers the
            // canvas shows drifting, instead of jittering around a flat mean.
            // The spread is wide on purpose: with a narrow one no declaration
            // ever lands under the thirty-day threshold, and the « ≤ 30 j »
            // column reads a flat zero for every insurer but the fastest.
            $delay = (int) round(
                $profile['delay'] + ($profile['drift'] * (self::MONTHS - 1 - $back)) + random_int(-14, 14),
            );

            [$received, $status, $manual] = $this->outcome($invoiced, $profile['rejection'], $back);

            // The monthly invoice is filed at the end of the month it covers —
            // never in the future for the month currently running.
            $deposited = $month->copy()->endOfMonth()->startOfDay()->min(now()->startOfDay());

            Declaration::query()->updateOrCreate(
                [
                    'pharmacy_id' => $pharmacy->id,
                    'insurer_id' => $insurer->id,
                    'period_year' => $month->year,
                    'period_month' => $month->month,
                ],
                [
                    'amount_invoiced' => $invoiced,
                    'amount_received' => $received,
                    'status' => $status,
                    'is_status_manual' => $manual,
                    'invoice_deposited_on' => $deposited,
                    'paid_on' => $status->isSettled()
                        ? $deposited->copy()->addDays(max(1, $delay))
                        : null,
                    'private_note' => $status === DeclarationStatus::Rejected
                        ? 'motif absence ordonnance'
                        : null,
                ],
            );
        }
    }

    /**
     * What became of one month's invoice.
     *
     * The chance of still being owed money decays sharply with age: a year-old
     * invoice has usually been settled or chased, this month's has not. Without
     * that decay the « > 90 j » band swallows everything, because it spans nine
     * months against one for « 0–30 j » — the arithmetic, not the realism,
     * would then decide the shape of the ageing chart.
     *
     * @return array{0: int, 1: DeclarationStatus, 2: bool}
     */
    protected function outcome(int $invoiced, float $rejection, int $monthsBack): array
    {
        if (random_int(1, 100) <= (int) round($rejection * 100)) {
            return [0, DeclarationStatus::Rejected, true];
        }

        $stillOwed = max(3, 55 - ($monthsBack * 9));

        if (random_int(1, 100) > $stillOwed) {
            return [$invoiced, DeclarationStatus::Paid, false];
        }

        // A fifth of what remains owed was never paid at all; the rest is part
        // payment, which is the case the derived status exists to make legible.
        if (random_int(1, 5) === 1) {
            return [0, DeclarationStatus::Unpaid, false];
        }

        $received = (int) round($invoiced * random_int(35, 80) / 100 / 1_000) * 1_000;

        return [$received, DeclarationStatus::Partial, false];
    }

    /**
     * One APhaSPB administrator, in the admin Joomla group.
     */
    protected function admin(): void
    {
        User::query()->firstOrCreate(['email' => 'admin@aphaspb.local'], [
            'joomla_user_id' => 1,
            'name' => 'Admin APhaSPB',
            'email_verified_at' => now(),
            'joomla_groups' => config('joomla.groups.admin') ?: [8],
            'token_version' => 0,
        ]);
    }
}
