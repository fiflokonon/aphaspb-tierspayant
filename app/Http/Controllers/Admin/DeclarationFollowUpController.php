<?php

namespace App\Http\Controllers\Admin;

use App\Data\Period;
use App\Data\PharmacyCompleteness;
use App\Enums\CompletenessState;
use App\Http\Controllers\Controller;
use App\Models\Pharmacy;
use App\Services\Network\DeclarationCompleteness;
use App\Support\MonthLabel;
use App\Support\PageSize;
use App\Support\WhatsappNumber;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Qui a déclaré quel mois, pour que le réseau relance — hors système.
 *
 * Exception délimitée au CDC (spec suivi des déclarations, §3) : un nom
 * d'officine à côté de l'état complet / partiel / rien de son mois. Rien
 * d'autre — ni assureur, ni nombre d'assureurs, ni montant. La relance passe
 * par WhatsApp : la plateforme ne l'envoie pas et n'en garde pas trace.
 */
class DeclarationFollowUpController extends Controller
{
    protected const PER_PAGE = 50;

    /** @var list<string> */
    protected const STATES = ['to-chase', 'all', 'complete', 'partial', 'none'];

    public function __construct(protected DeclarationCompleteness $completeness)
    {
        //
    }

    public function __invoke(Request $request): Response
    {
        $months = $this->months();
        $month = $this->month($request, $months);
        $city = $request->string('city')->value() ?: null;
        $state = in_array($request->string('state')->value(), self::STATES, true) ? $request->string('state')->value() : 'to-chase';

        $rows = $this->completeness->forMonth($month, $city);
        $shown = array_values(array_filter($rows, fn (PharmacyCompleteness $row): bool => match ($state) {
            'all' => true,
            'to-chase' => $row->state !== CompletenessState::Complete,
            default => $row->state->value === $state,
        }));

        $perPage = PageSize::resolve($request, self::PER_PAGE);
        $page = max(1, $request->integer('page', 1));

        $paginator = (new LengthAwarePaginator(
            array_map(fn (PharmacyCompleteness $row): array => $this->row($row, $month), array_slice($shown, ($page - 1) * $perPage, $perPage)),
            count($shown),
            $perPage,
            $page,
            ['path' => $request->url()],
        ))->withQueryString();

        return Inertia::render('admin/DeclarationFollowUp', [
            'month' => $this->key($month),
            'months' => array_map(fn (Period $one): array => [
                'value' => $this->key($one),
                'label' => $this->label($one),
            ], $months),
            'city' => $city,
            'cities' => Pharmacy::filterableCities(),
            'state' => $state,
            'summary' => [
                'complete' => count(array_filter($rows, fn ($row) => $row->state === CompletenessState::Complete)),
                'partial' => count(array_filter($rows, fn ($row) => $row->state === CompletenessState::Partial)),
                'none' => count(array_filter($rows, fn ($row) => $row->state === CompletenessState::None)),
                'total' => count($rows),
            ],
            'pharmacies' => $paginator,
        ]);
    }

    /**
     * Les douze mois terminés, du plus récent au plus ancien.
     *
     * @return list<Period>
     */
    protected function months(): array
    {
        $start = now()->startOfMonth();

        return array_map(fn (int $back): Period => new Period(
            $start->subMonths($back)->year,
            $start->subMonths($back)->month,
        ), range(1, 12));
    }

    /**
     * @param  list<Period>  $months
     */
    protected function month(Request $request, array $months): Period
    {
        foreach ($months as $one) {
            if ($this->key($one) === $request->string('month')->value()) {
                return $one;
            }
        }

        return $months[0];
    }

    /**
     * @return array{id: int, name: string, city: string|null, state: string, stateLabel: string, whatsappUrl: string|null}
     */
    protected function row(PharmacyCompleteness $row, Period $month): array
    {
        return [
            'id' => $row->id,
            'name' => $row->name,
            'city' => $row->city,
            'state' => $row->state->value,
            'stateLabel' => $row->state->label(),
            'whatsappUrl' => $row->whatsappPhone === null || $row->state === CompletenessState::Complete
                ? null
                : WhatsappNumber::link($row->whatsappPhone, $this->message($row, $month)),
        ];
    }

    protected function message(PharmacyCompleteness $row, Period $month): string
    {
        return sprintf(
            'Bonjour %s, votre déclaration de %s sur la plateforme APhaSPB est %s. Vous pouvez la compléter ici : %s. Merci !',
            $row->name,
            $this->label($month),
            $row->state === CompletenessState::Partial ? 'incomplète' : 'à faire',
            route('pharmacy.declare', ['year' => $month->year, 'month' => $month->month]),
        );
    }

    protected function key(Period $month): string
    {
        return sprintf('%04d-%02d', $month->year, $month->month);
    }

    /** « août 2026 » */
    protected function label(Period $month): string
    {
        return mb_strtolower(MonthLabel::long($month->month, $month->year));
    }
}
