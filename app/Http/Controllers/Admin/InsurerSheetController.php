<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Insurer;
use App\Models\Pharmacy;
use App\Services\Network\InsurerCreditors;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One insurer seen from the network: its clause, the officines that work with
 * it, and the officines it still owes money to.
 *
 * The creditors are named without an amount each; their total only shows on
 * the anonymity threshold — see InsurerCreditors, the one place deciding it.
 */
class InsurerSheetController extends Controller
{
    public function __invoke(Insurer $insurer, InsurerCreditors $creditors): Response
    {
        $owed = $creditors->for($insurer);

        return Inertia::render('admin/InsurerSheet', [
            'insurer' => [
                'id' => $insurer->id,
                'name' => $insurer->name,
                'isActive' => $insurer->is_active,
                'standardDelayDays' => $insurer->standard_delay_days,
                'penaltyTriggerDays' => $insurer->penalty_trigger_days,
                'penaltyRatePercent' => $insurer->penaltyRatePercent(),
            ],
            'partners' => $insurer->pharmacies()
                ->orderBy('name')
                ->get(['pharmacies.id', 'pharmacies.name', 'pharmacies.slug', 'pharmacies.city'])
                ->map($this->row(...)),
            'creditors' => $owed['creditors']->map($this->row(...)),
            'outstanding' => $owed['outstanding'],
        ]);
    }

    /**
     * @return array{name: string, slug: string, city: string|null}
     */
    protected function row(Pharmacy $pharmacy): array
    {
        return ['name' => $pharmacy->name, 'slug' => $pharmacy->slug, 'city' => $pharmacy->city];
    }
}
