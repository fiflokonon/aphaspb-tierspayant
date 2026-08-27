<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveInsurerRequest;
use App\Http\Requests\Admin\SaveThresholdRequest;
use App\Models\Insurer;
use App\Services\Settings\SettingsRepository;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The APhaSPB's list of insurers and brokers, and the reference threshold.
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
                    'pharmacies' => $insurer->pharmacies_count,
                ]),
            'threshold' => $this->settings->paymentDelayThresholdDays(),
            'anonymityMinimum' => $this->settings->anonymityMinPharmacies(),
        ]);
    }

    public function store(SaveInsurerRequest $request): RedirectResponse
    {
        Insurer::query()->create([
            'name' => $request->validated('name'),
            'is_active' => $request->boolean('is_active', true),
        ]);

        return to_route('admin.insurers');
    }

    public function update(SaveInsurerRequest $request, Insurer $insurer): RedirectResponse
    {
        $insurer->update([
            'name' => $request->validated('name'),
            'is_active' => $request->has('is_active')
                ? $request->boolean('is_active')
                : $insurer->is_active,
        ]);

        return to_route('admin.insurers');
    }

    /**
     * The payment threshold only — see SaveThresholdRequest for why.
     */
    public function updateThreshold(SaveThresholdRequest $request): RedirectResponse
    {
        $this->settings->set(
            SettingsRepository::PAYMENT_DELAY_THRESHOLD_DAYS,
            $request->integer('payment_delay_threshold_days'),
        );

        return to_route('admin.insurers');
    }
}
