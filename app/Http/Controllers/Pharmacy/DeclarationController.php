<?php

namespace App\Http\Controllers\Pharmacy;

use App\Actions\Declarations\RecordDeclarationRevision;
use App\Actions\Declarations\RecordPaymentInstalments;
use App\Data\Period;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pharmacy\SaveDeclarationRequest;
use App\Models\Declaration;
use App\Models\DeclarationRevision;
use App\Models\Pharmacy;
use App\Services\Declarations\DeclarationCalendar;
use App\Services\Declarations\MonthlyDeclarationRun;
use App\Support\MonthLabel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Screen 3a — one insurer at a time, two amounts, a derived status.
 *
 * The round is rebuilt from stored declarations on every request rather than
 * held in a session, so an officine interrupted mid-round simply comes back.
 */
class DeclarationController extends Controller
{
    public function __construct(protected DeclarationCalendar $calendar)
    {
        //
    }

    public function show(Request $request): Response
    {
        $pharmacy = $request->user()->currentPharmacy;
        $period = $this->period($request);
        $run = new MonthlyDeclarationRun($pharmacy, $period);

        $insurer = $request->integer('insurer') !== 0
            ? $run->insurer($request->integer('insurer'))
            : $run->nextInsurer();

        if ($insurer === null) {
            return Inertia::render('pharmacy/DeclareDone', [
                'declared' => $run->declaredCount(),
                'period' => $this->periodPayload($period),
                'periods' => $this->selectablePeriods($pharmacy),
                'dashboardUrl' => route('dashboard', ['current_pharmacy' => $pharmacy->slug]),
            ]);
        }

        $declaration = $run->declarationFor($insurer);

        return Inertia::render('pharmacy/Declare', [
            'insurer' => [
                'id' => $insurer->id,
                'name' => $insurer->name,
                'standardDelayDays' => $insurer->standard_delay_days,
            ],
            'progress' => $run->progressFor($insurer),
            'period' => $this->periodPayload($period),
            'periods' => $this->selectablePeriods($pharmacy),
            // The two date fields are bounded by the same span the request
            // validates, so the browser refuses what the server would refuse.
            'dateBounds' => [
                'earliest' => sprintf('%04d-%02d-01', $period->year, $period->month),
                'latest' => now()->toDateString(),
            ],
            'declaration' => $declaration === null ? null : [
                'amount_invoiced' => $declaration->amount_invoiced,
                'amount_received' => $declaration->amount_received,
                'status' => $declaration->status,
                'is_status_manual' => $declaration->is_status_manual,
                'invoice_deposited_on' => $declaration->invoice_deposited_on?->toDateString(),
                'paid_on' => $declaration->paid_on?->toDateString(),
                'delay_days' => $declaration->delay_days,
                'private_note' => $declaration->private_note,
                // Les versements repartent tels qu'ils sont enregistrés : la
                // correction d'un mois se fait sur les lignes elles-mêmes, pas
                // sur un total qui aurait perdu le détail des dates.
                'payments' => $declaration->payments->map(fn ($payment): array => [
                    'amount' => $payment->amount,
                    'paid_on' => $payment->paid_on->toDateString(),
                    'delay_days' => $payment->delay_days,
                ])->all(),
                // L'historique des corrections, du plus récent au plus ancien.
                // La première révision est l'état d'origine, pas une
                // correction : l'écran ne compte donc que les suivantes.
                'revisions' => $declaration->revisions->sortByDesc('id')->values()->map(
                    fn (DeclarationRevision $revision): array => [
                        'recordedAt' => $revision->created_at?->toIso8601String(),
                        'authorName' => $revision->author_name,
                        'amountInvoiced' => $revision->amount_invoiced,
                        'amountReceived' => $revision->amount_received,
                        'statusLabel' => $revision->status->label(),
                        'invoiceDepositedOn' => $revision->invoice_deposited_on?->toDateString(),
                        'delayDays' => $revision->delay_days,
                        'payments' => $revision->payments,
                    ],
                )->all(),
            ],
        ]);
    }

    public function store(
        SaveDeclarationRequest $request,
        RecordPaymentInstalments $recordInstalments,
        RecordDeclarationRevision $recordRevision,
    ): RedirectResponse {
        $pharmacy = $request->user()->currentPharmacy;

        // Les trois écritures tiennent ou tombent ensemble. Sans cela, une
        // révision qui échoue laisse la déclaration et ses versements déjà
        // committés : la trace perd cet enregistrement, et la sauvegarde
        // suivante se compare à une révision périmée, donc enregistre une
        // correction réelle comme si c'était la précédente.
        $declaration = DB::transaction(function () use ($request, $pharmacy, $recordInstalments, $recordRevision) {
            $declaration = Declaration::query()->updateOrCreate(
                [
                    'pharmacy_id' => $pharmacy->id,
                    'insurer_id' => $request->integer('insurer_id'),
                    'period_year' => $request->integer('period_year'),
                    'period_month' => $request->integer('period_month'),
                ],
                [
                    'amount_invoiced' => $request->integer('amount_invoiced'),
                    'status' => $request->resolvedStatus(),
                    'is_status_manual' => $request->isStatusManual(),
                    // Neither the received total, the payment date nor the
                    // delay is stored from here: the instalments below are the
                    // source of all three, so the client has no say over them.
                    'invoice_deposited_on' => $request->date('invoice_deposited_on'),
                    'private_note' => $request->input('private_note') ?: null,
                ],
            );

            // Written after the declaration, and only then: the delay of each
            // transfer is counted from the deposit date this save has settled.
            $recordInstalments->handle($declaration, $request->instalments());

            // Après les versements, jamais avant : une révision antérieure
            // photographierait les totaux de l'enregistrement précédent.
            $recordRevision->handle($declaration->load('payments'), $request->user());

            return $declaration;
        });

        // Carry the period only when catching up on a past month: without it
        // each save would bounce back to the current month.
        $year = $request->integer('period_year');
        $month = $request->integer('period_month');
        $isCurrentMonth = $year === now()->year && $month === now()->month;

        return to_route('pharmacy.declare', $isCurrentMonth ? [] : [
            'year' => $year,
            'month' => $month,
        ]);
    }

    /**
     * The month being declared: the current one unless the officine picked
     * another to catch up on.
     */
    protected function period(Request $request): Period
    {
        $year = $request->integer('year');
        $month = $request->integer('month');

        if ($year === 0 || $month < 1 || $month > 12) {
            return new Period(now()->year, now()->month);
        }

        return new Period($year, $month);
    }

    /**
     * The months the officine may switch to, newest first.
     *
     * Reaching a missed month used to mean typing the query string by hand.
     *
     * @return list<array{year: int, month: int, label: string, isComplete: bool, isCurrent: bool, url: string}>
     */
    protected function selectablePeriods(Pharmacy $pharmacy): array
    {
        return array_map(
            fn (array $month): array => [
                ...$month,
                'url' => route('pharmacy.declare', [
                    'year' => $month['year'],
                    'month' => $month['month'],
                ]),
            ],
            $this->calendar->months($pharmacy),
        );
    }

    /**
     * @return array{year: int, month: int, label: string}
     */
    protected function periodPayload(Period $period): array
    {
        return [
            'year' => $period->year,
            'month' => $period->month,
            'label' => MonthLabel::long($period->month, $period->year),
        ];
    }
}
