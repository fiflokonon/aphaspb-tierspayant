<?php

namespace App\Http\Controllers\Pharmacy;

use App\Http\Controllers\Controller;
use App\Models\Declaration;
use App\Models\Insurer;
use App\Support\Fcfa;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The officine's own register of declarations.
 *
 * The only screen where the private note appears — the CDC reserves it to the
 * officine, and no admin route may return it. It is also where a past month is
 * corrected, so every row carries the URL that reopens it.
 */
class DeclarationHistoryController extends Controller
{
    protected const PER_PAGE = 20;

    public function __invoke(Request $request): Response
    {
        $pharmacy = $request->user()->currentPharmacy;

        // The filter list comes from the declarations, not from the currently
        // ticked insurers: an officine that stops working with an insurer keeps
        // its past declarations, and must still be able to filter on them.
        $declared = Insurer::query()
            ->whereIn('id', Declaration::query()
                ->where('pharmacy_id', $pharmacy->id)
                ->distinct()
                ->pluck('insurer_id'))
            ->orderBy('name')
            ->get(['id', 'name']);

        // Applied whenever given: an id this officine never declared to simply
        // matches nothing, which is honest, rather than being dropped silently.
        $insurerId = $request->integer('insurer') ?: null;

        $years = Declaration::query()
            ->where('pharmacy_id', $pharmacy->id)
            ->distinct()
            ->orderByDesc('period_year')
            ->pluck('period_year')
            ->all();

        $year = $request->integer('year');
        $year = in_array($year, $years, true) ? $year : null;

        $declarations = Declaration::query()
            ->with('insurer:id,name')
            ->where('pharmacy_id', $pharmacy->id)
            ->when($insurerId, fn ($query) => $query->where('insurer_id', $insurerId))
            ->when($year, fn ($query) => $query->where('period_year', $year))
            ->orderByDesc('period_year')
            ->orderByDesc('period_month')
            ->orderBy('insurer_id')
            // Seven insurers over several years runs into the hundreds, so the
            // register is paged. withQueryString keeps the two filters on every
            // page link instead of silently widening the list on page two.
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Declaration $declaration) => [
                'id' => $declaration->id,
                'insurerName' => $declaration->insurer->name,
                'year' => $declaration->period_year,
                'month' => $declaration->period_month,
                'monthLabel' => $this->monthLabel($declaration->period_month, $declaration->period_year),
                'status' => $declaration->status->value,
                'statusLabel' => $declaration->status->label(),
                'invoiced' => Fcfa::format($declaration->amount_invoiced),
                'received' => Fcfa::format($declaration->amount_received),
                'outstanding' => $declaration->amount_outstanding > 0
                    ? Fcfa::format($declaration->amount_outstanding)
                    : null,
                'delayDays' => $declaration->delay_days,
                'privateNote' => $declaration->private_note,
                'editUrl' => route('pharmacy.declare', [
                    'insurer' => $declaration->insurer_id,
                    'year' => $declaration->period_year,
                    'month' => $declaration->period_month,
                ], absolute: false),
            ]);

        return Inertia::render('pharmacy/History', [
            'declarations' => $declarations,
            'insurers' => $declared,
            'years' => $years,
            'filters' => [
                'insurer' => $insurerId,
                'year' => $year,
            ],
        ]);
    }

    /**
     * « Août 26 », as the canvas writes it.
     */
    protected function monthLabel(int $month, int $year): string
    {
        $months = [
            1 => 'Janv.', 'Févr.', 'Mars', 'Avr.', 'Mai', 'Juin',
            'Juil.', 'Août', 'Sept.', 'Oct.', 'Nov.', 'Déc.',
        ];

        return $months[$month].' '.substr((string) $year, 2);
    }
}
