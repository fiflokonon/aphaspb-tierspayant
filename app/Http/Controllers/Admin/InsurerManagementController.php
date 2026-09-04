<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveInsurerRequest;
use App\Models\Insurer;
use App\Services\Settings\SettingsRepository;
use Illuminate\Http\RedirectResponse;
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
        ]);

        return to_route('admin.insurers');
    }

    public function update(SaveInsurerRequest $request, Insurer $insurer): RedirectResponse
    {
        $insurer->update([
            'name' => $request->validated('name', $insurer->name),
            'is_active' => $request->has('is_active')
                ? $request->boolean('is_active')
                : $insurer->is_active,
            'standard_delay_days' => $request->has('standard_delay_days')
                ? $request->integer('standard_delay_days')
                : $insurer->standard_delay_days,
        ]);

        return to_route('admin.insurers');
    }
}
