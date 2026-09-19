<?php

namespace App\Http\Controllers\Pharmacy;

use App\Data\OverdueLine;
use App\Http\Controllers\Controller;
use App\Models\Pharmacy;
use App\Models\PharmacyInvitation;
use App\Services\Declarations\DeclarationCalendar;
use App\Services\Declarations\OverduePaymentsService;
use App\Services\Pharmacy\PharmacyStatsService;
use App\Support\MonthLabel;
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

    /** Combien de lignes en retard la table montre avant de renvoyer au registre. */
    protected const OVERDUE_SHOWN = 8;

    public function __construct(
        protected PharmacyStatsService $stats,
        protected DeclarationCalendar $calendar,
        protected OverduePaymentsService $overdue,
    ) {
        //
    }

    public function __invoke(Request $request): Response
    {
        $pharmacy = $request->user()->currentPharmacy;
        $recovery = $this->stats->recoveryByInsurer($pharmacy, self::MONTHS);
        $insurerId = $this->requestedInsurer($request, $recovery);
        $overdue = $this->overdue->forPharmacy($pharmacy);

        return Inertia::render('pharmacy/Dashboard', [
            'pharmacyName' => $pharmacy->name,
            'city' => $pharmacy->city,
            'summary' => $this->stats->summary($pharmacy, self::MONTHS),
            'ageing' => $this->stats->ageingBuckets($pharmacy),
            'owed' => $this->stats->outstandingByInsurer($pharmacy, self::MONTHS),
            'recovery' => $recovery,
            'overdue' => $this->overdueLines($overdue),
            'overdueSummary' => $this->overdueSummary($overdue),
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
     * Les pires factures en retard, prêtes à l'affichage.
     *
     * Tronquée volontairement : une officine portant quarante factures en
     * retard noierait le reste du tableau de bord, et le registre — qui sait
     * déjà filtrer par assureur — est fait pour la liste complète.
     *
     * @param  list<OverdueLine>  $overdue
     * @return list<array{declarationId: int, insurerId: int, insurerName: string, monthLabel: string, depositedOn: string, overdueDays: int, standardDelayDays: int, outstanding: int, penalty: int|null, insurerUrl: string}>
     */
    protected function overdueLines(array $overdue): array
    {
        return array_map(fn (OverdueLine $line): array => [
            'declarationId' => $line->declarationId,
            'insurerId' => $line->insurerId,
            'insurerName' => $line->insurerName,
            'monthLabel' => MonthLabel::short($line->periodMonth, $line->periodYear),
            'depositedOn' => $line->invoiceDepositedOn->toDateString(),
            // Le dépassement, pas l'âge brut : chaque assureur a son propre
            // délai, et « 120 jours » ne veut rien dire sans lui.
            'overdueDays' => $line->ageDays - $line->standardDelayDays,
            'standardDelayDays' => $line->standardDelayDays,
            'outstanding' => $line->outstanding,
            'penalty' => $line->penalty,
            'insurerUrl' => route('pharmacy.insurers.show', $line->insurerId, absolute: false),
        ], array_slice($overdue, 0, self::OVERDUE_SHOWN));
    }

    /**
     * Ce que le bandeau annonce, ou null quand rien n'est en retard.
     *
     * La pénalité annoncée ne couvre **que les factures en retard**, pas les
     * mois déjà soldés tardivement qui gardent leur pénalité acquise : un
     * bandeau chiffrant un total que la table juste en dessous ne retrouve pas
     * serait illisible. Le total réclamable vit sur la page par assureur.
     *
     * Le bandeau nomme la pire ligne plutôt que de compter au-delà d'un seuil
     * d'ancienneté : ConsoleNavigation::chaseNotice() compte déjà « au-delà de
     * 60 jours » depuis la fin du mois déclaré, là où ageDays compte depuis le
     * dépôt. Deux seuils voisins sur deux horloges se contrediraient.
     *
     * @param  list<OverdueLine>  $overdue
     * @return array{count: int, outstanding: int, penalty: int|null, worst: array{insurerName: string, monthLabel: string, overdueDays: int}, hidden: int, historyUrl: string}|null
     */
    protected function overdueSummary(array $overdue): ?array
    {
        if ($overdue === []) {
            return null;
        }

        $penalties = array_filter(
            array_map(fn (OverdueLine $line): ?int => $line->penalty, $overdue),
            fn (?int $penalty): bool => $penalty !== null,
        );

        // forPharmacy() rend la plus ancienne en tête.
        $worst = $overdue[0];

        return [
            'count' => count($overdue),
            'outstanding' => array_sum(array_map(fn (OverdueLine $line): int => $line->outstanding, $overdue)),
            'penalty' => $penalties === [] ? null : array_sum($penalties),
            'worst' => [
                'insurerName' => $worst->insurerName,
                'monthLabel' => MonthLabel::long($worst->periodMonth, $worst->periodYear),
                'overdueDays' => $worst->ageDays - $worst->standardDelayDays,
            ],
            'hidden' => max(0, count($overdue) - self::OVERDUE_SHOWN),
            'historyUrl' => route('pharmacy.history', absolute: false),
        ];
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
