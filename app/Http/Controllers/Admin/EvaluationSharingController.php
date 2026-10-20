<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveEvaluationSharingRequest;
use App\Services\Settings\SettingsRepository;
use Illuminate\Http\RedirectResponse;

/**
 * Whether the officines see the network's insurer evaluation averages.
 *
 * Only the switch lives here: what is published, and above which threshold,
 * stays InsurerEvaluationResults' decision whoever reads it.
 */
class EvaluationSharingController extends Controller
{
    public function __construct(protected SettingsRepository $settings)
    {
        //
    }

    public function __invoke(SaveEvaluationSharingRequest $request): RedirectResponse
    {
        $this->settings->set(
            SettingsRepository::EVALUATION_RESULTS_SHARED,
            $request->boolean('shared') ? 1 : 0,
        );

        return back();
    }
}
