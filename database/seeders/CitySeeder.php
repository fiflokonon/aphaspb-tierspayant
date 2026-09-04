<?php

namespace Database\Seeders;

use App\Models\City;
use Illuminate\Database\Seeder;

class CitySeeder extends Seeder
{
    /**
     * The 77 communes of Benin, by department.
     *
     * Bootstraps the suggestions the onboarding offers. Without them the first
     * officines to register invent their own spelling — « Abomey Calavi » next
     * to « Abomey-Calavi », « cotonou » next to « Cotonou » — and the network
     * statistics count them as different places ever after.
     *
     * The list proposes and never constrains: pharmacies.city stays free text,
     * so a locality missing here still registers.
     *
     * @var array<string, list<string>>
     */
    protected const COMMUNES = [
        'Alibori' => ['Banikoara', 'Gogounou', 'Kandi', 'Karimama', 'Malanville', 'Segbana'],
        'Atacora' => ['Boukoumbé', 'Cobly', 'Kérou', 'Kouandé', 'Matéri', 'Natitingou', 'Péhunco', 'Tanguiéta', 'Toucountouna'],
        'Atlantique' => ['Abomey-Calavi', 'Allada', 'Kpomassè', 'Ouidah', 'Sô-Ava', 'Toffo', 'Tori-Bossito', 'Zè'],
        'Borgou' => ['Bembéréké', 'Kalalé', "N'Dali", 'Nikki', 'Parakou', 'Pèrèrè', 'Sinendé', 'Tchaourou'],
        'Collines' => ['Bantè', 'Dassa-Zoumé', 'Glazoué', 'Ouèssè', 'Savalou', 'Savè'],
        'Couffo' => ['Aplahoué', 'Djakotomey', 'Dogbo', 'Klouékanmè', 'Lalo', 'Toviklin'],
        'Donga' => ['Bassila', 'Copargo', 'Djougou', 'Ouaké'],
        'Littoral' => ['Cotonou'],
        'Mono' => ['Athiémé', 'Bopa', 'Comè', 'Grand-Popo', 'Houéyogbé', 'Lokossa'],
        'Ouémé' => ['Adjarra', 'Adjohoun', 'Aguégués', 'Akpro-Missérété', 'Avrankou', 'Bonou', 'Dangbo', 'Porto-Novo', 'Sèmè-Kpodji'],
        'Plateau' => ['Adja-Ouèrè', 'Ifangni', 'Kétou', 'Pobè', 'Sakété'],
        'Zou' => ['Abomey', 'Agbangnizoun', 'Bohicon', 'Covè', 'Djidja', 'Ouinhi', 'Za-Kpota', 'Zagnanado', 'Zogbodomey'],
    ];

    /**
     * Run the database seeds.
     *
     * Idempotent, and safe to re-run on an installed database: a commune the
     * association renamed by hand is left alone rather than overwritten.
     */
    public function run(): void
    {
        foreach (self::COMMUNES as $department => $communes) {
            foreach ($communes as $name) {
                City::query()->firstOrCreate(
                    ['name' => $name],
                    ['department' => $department],
                );
            }
        }
    }
}
