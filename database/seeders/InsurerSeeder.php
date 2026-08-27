<?php

namespace Database\Seeders;

use App\Models\Insurer;
use Illuminate\Database\Seeder;

class InsurerSeeder extends Seeder
{
    /**
     * The insurers and brokers named in the CDC and the design canvas.
     *
     * Kept as a literal list rather than a config file: the admin edits this
     * table through the interface, the seeder only bootstraps it.
     *
     * @var list<string>
     */
    protected const INSURERS = [
        'SUNU Assurances',
        'NSIA Assurances',
        "L'Africaine des Assurances",
        'Sanlam Assurances',
        'Atlantique Assurances',
        'Courtier — Ascoma Bénin',
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (self::INSURERS as $name) {
            Insurer::query()->firstOrCreate(['name' => $name]);
        }
    }
}
