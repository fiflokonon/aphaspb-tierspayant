<?php

namespace App\Http\Controllers\Pharmacy;

use App\Http\Controllers\Controller;
use App\Models\PharmacyInvitation;
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

    public function __construct(protected PharmacyStatsService $stats)
    {
        //
    }

    public function __invoke(Request $request): Response
    {
        $pharmacy = $request->user()->currentPharmacy;

        return Inertia::render('pharmacy/Dashboard', [
            'pharmacyName' => $pharmacy->name,
            'city' => $pharmacy->city,
            'summary' => $this->stats->summary($pharmacy, self::MONTHS),
            'ageing' => $this->stats->ageingBuckets($pharmacy),
            'owed' => $this->stats->outstandingByInsurer($pharmacy, self::MONTHS),
            'recovery' => $this->stats->recoveryByInsurer($pharmacy, self::MONTHS),
            'declareUrl' => route('pharmacy.declare'),
            'pendingInvitations' => $this->pendingInvitations($request),

            // The chart is the only expensive read on this page, so it arrives
            // after the first paint rather than delaying it.
            'journey' => Inertia::defer(
                fn () => $this->stats->monthlyJourney($pharmacy, self::MONTHS),
            ),
        ]);
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
