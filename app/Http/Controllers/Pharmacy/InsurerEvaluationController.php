<?php

namespace App\Http\Controllers\Pharmacy;

use App\Enums\EvaluationCriterion;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pharmacy\SaveInsurerEvaluationRequest;
use App\Models\EvaluationCampaign;
use App\Models\Insurer;
use App\Models\InsurerEvaluation;
use App\Models\Pharmacy;
use App\Services\Network\InsurerEvaluationResults;
use App\Services\Pharmacy\InsurerEvaluationSuggestions;
use App\Services\Settings\SettingsRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * L'évaluation annuelle des assureurs par l'officine.
 *
 * Elle ne s'écrit que pendant une campagne ouverte par l'admin. La note du
 * délai de règlement est proposée d'après les déclarations de l'année
 * évaluée ; l'officine la valide ou la corrige, comme les sept autres.
 */
class InsurerEvaluationController extends Controller
{
    public function __construct(
        protected InsurerEvaluationSuggestions $suggestions,
        protected InsurerEvaluationResults $results,
        protected SettingsRepository $settings,
    ) {
        //
    }

    public function index(Request $request): Response
    {
        $pharmacy = $request->user()->currentPharmacy;
        $campaign = EvaluationCampaign::current();

        $evaluations = InsurerEvaluation::query()
            ->with(['campaign', 'insurer:id,name'])
            ->where('pharmacy_id', $pharmacy->id)
            ->get();

        return Inertia::render('pharmacy/Evaluations', [
            'campaign' => $campaign === null ? null : [
                'year' => $campaign->year,
                'closesOn' => $campaign->closes_on->toDateString(),
            ],
            'insurers' => $campaign === null ? [] : $campaign->insurersEvaluableBy($pharmacy)->orderBy('name')->get(['id', 'name'])
                ->map(function (Insurer $insurer) use ($evaluations, $campaign): array {
                    $evaluation = $evaluations->first(fn (InsurerEvaluation $one): bool => $one->evaluation_campaign_id === $campaign->id
                        && $one->insurer_id === $insurer->id);

                    return [
                        'id' => $insurer->id,
                        'name' => $insurer->name,
                        'total' => $evaluation?->total(),
                        'authorName' => $evaluation?->author_name,
                        'updatedOn' => $evaluation?->updated_at?->toDateString(),
                        'url' => route('pharmacy.evaluations.edit', $insurer->id, absolute: false),
                    ];
                })
                ->all(),
            'history' => $this->history($evaluations, $campaign),
            'network' => $this->network($evaluations),
            'totalPoints' => EvaluationCriterion::totalPoints(),
        ]);
    }

    public function edit(Request $request, Insurer $insurer): Response|RedirectResponse
    {
        $pharmacy = $request->user()->currentPharmacy;
        $campaign = EvaluationCampaign::current();

        if ($campaign === null) {
            return $this->noOpenCampaign();
        }

        abort_unless($this->isEvaluable($pharmacy, $insurer, $campaign), 404);

        $evaluation = InsurerEvaluation::query()
            ->where('evaluation_campaign_id', $campaign->id)
            ->where('pharmacy_id', $pharmacy->id)
            ->where('insurer_id', $insurer->id)
            ->first();

        return Inertia::render('pharmacy/Evaluate', [
            'campaign' => [
                'year' => $campaign->year,
                'closesOn' => $campaign->closes_on->toDateString(),
            ],
            'insurer' => [
                'id' => $insurer->id,
                'name' => $insurer->name,
            ],
            'criteria' => EvaluationCriterion::scale(),
            'totalPoints' => EvaluationCriterion::totalPoints(),
            'scores' => $evaluation?->scores(),
            'authorName' => $evaluation?->author_name,
            'suggestions' => $this->suggestions->for($pharmacy, $insurer, $campaign->year),
            'paymentDelayScale' => InsurerEvaluationSuggestions::paymentDelayScale(),
            'submitUrl' => route('pharmacy.evaluations.update', $insurer->id, absolute: false),
            'backUrl' => route('pharmacy.evaluations', absolute: false),
        ]);
    }

    public function update(SaveInsurerEvaluationRequest $request, Insurer $insurer): RedirectResponse
    {
        $pharmacy = $request->user()->currentPharmacy;
        $campaign = EvaluationCampaign::current();

        if ($campaign === null) {
            return $this->noOpenCampaign();
        }

        abort_unless($this->isEvaluable($pharmacy, $insurer, $campaign), 404);

        // Recalculée ici plutôt que reçue du formulaire : la note proposée
        // est celle des chiffres, pas celle qu'un client prétend avoir vue.
        $suggested = $this->suggestions->for($pharmacy, $insurer, $campaign->year)['paymentDelay']['points'];

        InsurerEvaluation::query()->updateOrCreate(
            [
                'evaluation_campaign_id' => $campaign->id,
                'pharmacy_id' => $pharmacy->id,
                'insurer_id' => $insurer->id,
            ],
            [
                ...$request->validated(),
                'suggested_payment_delay' => $suggested,
                'user_id' => $request->user()->id,
                'author_name' => $request->user()->name,
            ],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => "Évaluation de {$insurer->name} enregistrée."]);

        return to_route('pharmacy.evaluations');
    }

    protected function isEvaluable(Pharmacy $pharmacy, Insurer $insurer, EvaluationCampaign $campaign): bool
    {
        return $campaign->insurersEvaluableBy($pharmacy)->whereKey($insurer->id)->exists();
    }

    /**
     * Les évaluations des campagnes passées, la plus récente d'abord.
     *
     * @param  Collection<int, InsurerEvaluation>  $evaluations
     * @return list<array{year: int, insurers: list<array{name: string, total: int}>}>
     */
    protected function history(Collection $evaluations, ?EvaluationCampaign $current): array
    {
        return array_values($evaluations
            ->reject(fn (InsurerEvaluation $one): bool => $one->evaluation_campaign_id === $current?->id)
            ->groupBy(fn (InsurerEvaluation $one): int => $one->campaign->year)
            ->sortKeysDesc()
            ->map(fn ($group, int $year): array => [
                'year' => $year,
                'insurers' => array_values($group
                    ->sortBy(fn (InsurerEvaluation $one): string => $one->insurer->name)
                    ->map(fn (InsurerEvaluation $one): array => [
                        'name' => $one->insurer->name,
                        'total' => $one->total(),
                    ])
                    ->all()),
            ])
            ->all());
    }

    /**
     * Les moyennes du réseau de la dernière campagne close, si l'admin les partage.
     *
     * L'officine y lit aussi sa propre note, à côté : c'est la sienne.
     *
     * @param  Collection<int, InsurerEvaluation>  $evaluations
     * @return array{year: int, insurers: list<array<string, mixed>>}|null
     */
    protected function network(Collection $evaluations): ?array
    {
        if (! $this->settings->evaluationResultsShared()) {
            return null;
        }

        $campaign = EvaluationCampaign::query()->closed()->orderByDesc('year')->first();

        if ($campaign === null) {
            return null;
        }

        $own = $evaluations
            ->where('evaluation_campaign_id', $campaign->id)
            ->mapWithKeys(fn (InsurerEvaluation $one): array => [$one->insurer_id => $one->total()]);

        return [
            'year' => $campaign->year,
            'insurers' => array_map(fn (array $row): array => [
                'insurerName' => $row['insurerName'],
                'withheld' => $row['withheld'],
                'total' => $row['total'],
                'ownTotal' => $own[$row['insurerId']] ?? null,
            ], $this->results->for($campaign) ?? []),
        ];
    }

    protected function noOpenCampaign(): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => 'Aucune campagne d’évaluation n’est ouverte.']);

        return to_route('pharmacy.evaluations');
    }
}
