<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Declarations\ReconcilePenaltySettlement;
use App\Actions\Declarations\RecordDeclarationRevision;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveInsurerRequest;
use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\User;
use App\Services\Settings\SettingsRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The APhaSPB's list of insurers and brokers, and their agreed payment delays.
 *
 * Deactivating an insurer hides it from the officine forms and changes nothing
 * else: its declarations stay, and so do the statistics computed from them. A
 * name typed by an officine through the onboarding's free-text entry arrives
 * inactive and is approved here.
 */
class InsurerManagementController extends Controller
{
    public function __construct(protected SettingsRepository $settings)
    {
        //
    }

    public function index(): Response
    {
        return Inertia::render('admin/Insurers', [
            'insurers' => Insurer::query()
                ->withCount('pharmacies')
                ->orderBy('name')
                ->get()
                ->map(fn (Insurer $insurer) => [
                    'id' => $insurer->id,
                    'name' => $insurer->name,
                    'isActive' => $insurer->is_active,
                    'standardDelayDays' => $insurer->standard_delay_days,
                    'penaltyTriggerDays' => $insurer->penalty_trigger_days,
                    'penaltyRatePercent' => $insurer->penaltyRatePercent(),
                    'pharmacies' => $insurer->pharmacies_count,
                ]),
            'anonymityMinimum' => $this->settings->anonymityMinPharmacies(),
            'anonymityFloor' => SettingsRepository::ANONYMITY_FLOOR,
        ]);
    }

    public function store(SaveInsurerRequest $request): RedirectResponse
    {
        Insurer::query()->create([
            'name' => $request->validated('name'),
            'is_active' => $request->boolean('is_active', true),
            'standard_delay_days' => $request->integer(
                'standard_delay_days',
                Insurer::DEFAULT_STANDARD_DELAY_DAYS,
            ),
            // Pas de garde sur la présence, contrairement à update() : là-bas
            // l'absence signifie « inchangé » parce que l'écran édite un champ
            // à la fois, ici elle signifie « pas de clause », ce qui est déjà
            // la valeur par défaut des deux colonnes. `integer()` rend 0 sur
            // une clé absente, d'où le `?: null`.
            'penalty_trigger_days' => $request->integer('penalty_trigger_days') ?: null,
            'penalty_rate_bp' => $this->rateInBasisPoints($request),
        ]);

        return to_route('admin.insurers');
    }

    public function update(
        SaveInsurerRequest $request,
        Insurer $insurer,
        ReconcilePenaltySettlement $reconcile,
        RecordDeclarationRevision $revisions,
    ): RedirectResponse {
        $insurer->fill([
            'name' => $request->validated('name', $insurer->name),
            'is_active' => $request->has('is_active')
                ? $request->boolean('is_active')
                : $insurer->is_active,
            'standard_delay_days' => $request->has('standard_delay_days')
                ? $request->integer('standard_delay_days')
                : $insurer->standard_delay_days,
            // Les deux champs arrivent ensemble ou pas du tout : tester l'un
            // suffit, et le faire ici garde la clause intacte quand l'écran ne
            // renvoie qu'un renommage.
            ...$request->has('penalty_trigger_days') ? [
                'penalty_trigger_days' => $request->integer('penalty_trigger_days') ?: null,
                'penalty_rate_bp' => $this->rateInBasisPoints($request),
            ] : [],
        ]);

        $reopened = DB::transaction(function () use ($insurer, $reconcile, $revisions, $request): int {
            $clauseChanged = $insurer->isDirty(['penalty_trigger_days', 'penalty_rate_bp']);

            $insurer->save();

            return $clauseChanged
                ? $this->reconcileClosures($insurer, $reconcile, $revisions, $request->user())
                : 0;
        });

        if ($reopened > 0) {
            Inertia::flash('toast', [
                'type' => 'info',
                'message' => "{$reopened} pénalité(s) close(s) rouvertes : le montant a changé avec la clause.",
            ]);
        }

        return to_route('admin.insurers');
    }

    /**
     * Lever les clôtures que la nouvelle clause ne justifie plus.
     *
     * Le montant clos doit égaler la pénalité calculée : due, agrégats et
     * journal n'en retiennent que l'issue. Une clause changée change le calcul
     * sans qu'aucun mois soit réenregistré ; sans cette passe, la clôture
     * survivrait avec un montant faux, puis tomberait au prochain
     * enregistrement de l'officine avec un message dont elle n'est pas
     * l'auteur. Chaque levée laisse une révision signée de l'administrateur.
     *
     * @return int le nombre de clôtures levées
     */
    protected function reconcileClosures(
        Insurer $insurer,
        ReconcilePenaltySettlement $reconcile,
        RecordDeclarationRevision $revisions,
        User $admin,
    ): int {
        $reopened = 0;

        $closed = Declaration::query()
            ->where('insurer_id', $insurer->id)
            ->whereNotNull('penalty_settlement')
            ->with(['insurer', 'payments'])
            ->get();

        foreach ($closed as $declaration) {
            if ($reconcile->reconcile($declaration) !== null) {
                $revisions->handle($declaration, $admin);
                $reopened++;
            }
        }

        return $reopened;
    }

    /**
     * Le taux saisi en pourcentage, ramené en points de base.
     *
     * La conversion vit ici plutôt que dans le modèle : la base de données est
     * la source de vérité et elle compte en points de base ; le pourcentage
     * n'est qu'une commodité de saisie, comme le montant formaté d'AmountField.
     */
    protected function rateInBasisPoints(SaveInsurerRequest $request): ?int
    {
        $percent = $request->validated('penalty_rate_percent');

        return $percent === null || $percent === '' ? null : (int) round((float) $percent * 100);
    }
}
