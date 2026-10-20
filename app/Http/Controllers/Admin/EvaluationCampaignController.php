<?php

namespace App\Http\Controllers\Admin;

use App\Enums\EvaluationCriterion;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveEvaluationCampaignRequest;
use App\Models\EvaluationCampaign;
use App\Services\Network\InsurerEvaluationResults;
use App\Services\Settings\SettingsRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Les campagnes d'évaluation des assureurs, et ce que le réseau en retient.
 *
 * L'admin ouvre une campagne sur une année et une fenêtre de réponse. Les
 * moyennes ne sortent qu'une fois la campagne close, sous le seuil
 * d'anonymat : InsurerEvaluationResults en décide seul.
 */
class EvaluationCampaignController extends Controller
{
    public function __construct(
        protected InsurerEvaluationResults $results,
        protected SettingsRepository $settings,
    ) {
        //
    }

    public function index(Request $request): Response
    {
        $campaigns = EvaluationCampaign::query()->orderByDesc('year')->get();

        // Un compte tous assureurs confondus : combien d'officines ont
        // répondu, jamais à quel assureur.
        $participants = DB::table('insurer_evaluations')
            ->selectRaw('evaluation_campaign_id, COUNT(DISTINCT pharmacy_id) as pharmacies')
            ->groupBy('evaluation_campaign_id')
            ->pluck('pharmacies', 'evaluation_campaign_id');

        $selected = $campaigns->firstWhere('year', $request->integer('year'))
            ?? $campaigns->first(fn (EvaluationCampaign $campaign): bool => $campaign->isClosed());

        return Inertia::render('admin/Evaluations', [
            'campaigns' => $campaigns->map(fn (EvaluationCampaign $campaign): array => [
                'id' => $campaign->id,
                'year' => $campaign->year,
                'opensOn' => $campaign->opens_on->toDateString(),
                'closesOn' => $campaign->closes_on->toDateString(),
                'state' => $campaign->state(),
                'participants' => (int) ($participants[$campaign->id] ?? 0),
            ])->all(),
            'selectedYear' => $selected?->year,
            'results' => $selected === null ? null : $this->results->for($selected),
            'criteria' => EvaluationCriterion::scale(),
            'totalPoints' => EvaluationCriterion::totalPoints(),
            'threshold' => $this->settings->anonymityMinPharmacies(),
            'resultsShared' => $this->settings->evaluationResultsShared(),
        ]);
    }

    public function store(SaveEvaluationCampaignRequest $request): RedirectResponse
    {
        EvaluationCampaign::query()->create($request->validated());

        return to_route('admin.evaluations');
    }

    public function update(SaveEvaluationCampaignRequest $request, EvaluationCampaign $campaign): RedirectResponse
    {
        $campaign->update($request->validated());

        return to_route('admin.evaluations', ['year' => $campaign->year]);
    }
}
