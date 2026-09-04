<?php

namespace App\Http\Controllers\Pharmacy;

use App\Http\Controllers\Controller;
use App\Models\Pharmacy;
use App\Models\PharmacyInvitation;
use App\Services\Declarations\DeclarationCalendar;
use App\Services\Pharmacy\PharmacyStatsService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Screen 3b — the officine's payment journey over twelve months.
 *
 * Amounts turn the dashboard into a collection tool: the invoiced-versus-
 * collected gap month by month, the age of what is owed, and which insurer to
 * chase. All of it scoped to this officine by PharmacyStatsService.
 */
class PaymentJourneyController extends Controller
{
    /** How far back the journey looks. */
    protected const MONTHS = 12;

    public function __construct(
        protected PharmacyStatsService $stats,
        protected DeclarationCalendar $calendar,
    ) {
        //
    }

    public function __invoke(Request $request): Response
    {
        $pharmacy = $request->user()->currentPharmacy;
        $recovery = $this->stats->recoveryByInsurer($pharmacy, self::MONTHS);
        $insurerId = $this->requestedInsurer($request, $recovery);

        return Inertia::render('pharmacy/Dashboard', [
            'pharmacyName' => $pharmacy->name,
            'city' => $pharmacy->city,
            'summary' => $this->stats->summary($pharmacy, self::MONTHS),
            'ageing' => $this->stats->ageingBuckets($pharmacy),
            'owed' => $this->stats->outstandingByInsurer($pharmacy, self::MONTHS),
            'recovery' => $recovery,
            'filters' => ['insurer' => $insurerId],
            'declareUrl' => route('pharmacy.declare'),
            'outstandingMonths' => $this->outstandingMonths($pharmacy),
            'pendingInvitations' => $this->pendingInvitations($request),

            // The chart is the only expensive read on this page, so it arrives
            // after the first paint rather than delaying it.
            'journey' => Inertia::defer(
                fn () => $this->stats->monthlyJourney($pharmacy, self::MONTHS, $insurerId),
            ),
        ]);
    }

    /**
     * The insurer the journey is narrowed to, or null for all of them.
     *
     * Matched against the rows the screen already lists rather than against the
     * currently ticked insurers: recoveryByInsurer() also returns the ones this
     * officine has stopped working with but still declared to, and the select
     * has to be bound to a value that exists among its own options.
     *
     * @param  list<array{insurerId: int, insurerName: string, invoiced: int, received: int, outstanding: int, recoveryRate: float|null}>  $recovery
     */
    protected function requestedInsurer(Request $request, array $recovery): ?int
    {
        $requested = $request->integer('insurer');

        return in_array($requested, array_column($recovery, 'insurerId'), true)
            ? $requested
            : null;
    }

    /**
     * The months this officine still owes, oldest first.
     *
     * Catching up was always allowed and never offered: the screen only ever
     * linked to the month in progress, so a month missed in June stayed
     * missed. Each entry carries the link that opens its round.
     *
     * @return list<array{year: int, month: int, label: string, isComplete: bool, isCurrent: bool, url: string}>
     */
    protected function outstandingMonths(Pharmacy $pharmacy): array
    {
        return array_map(
            fn (array $month): array => [
                ...$month,
                'url' => route('pharmacy.declare', [
                    'year' => $month['year'],
                    'month' => $month['month'],
                ]),
            ],
            $this->calendar->outstanding($pharmacy),
        );
    }

    /**
     * Invitations to other officines awaiting this titulaire's answer.
     *
     * The artboard does not show these — it simply did not consider them. They
     * are kept rather than dropped: an invitation nobody can see is a feature
     * lost, and the alert sits above the header without disturbing the layout.
     *
     * @return list<array{code: string, inviterName: string, pharmacy: array{name: string, slug: string}}>
     */
    protected function pendingInvitations(Request $request): array
    {
        // array_values() rather than the collection's: PHPStan cannot prove
        // Collection::values()->all() yields a list.
        return array_values(PharmacyInvitation::query()
            ->with(['inviter', 'pharmacy'])
            ->whereRaw('LOWER(email) = ?', [strtolower($request->user()->email)])
            ->whereNull('accepted_at')
            ->where(fn ($query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>=', now()))
            ->latest()
            ->get()
            ->map(fn (PharmacyInvitation $invitation) => [
                'code' => $invitation->code,
                'inviterName' => $invitation->inviter->name,
                'pharmacy' => [
                    'name' => $invitation->pharmacy->name,
                    'slug' => $invitation->pharmacy->slug,
                ],
            ])
            ->all());
    }
}
