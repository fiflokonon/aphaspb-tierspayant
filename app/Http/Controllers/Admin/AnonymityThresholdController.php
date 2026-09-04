<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveAnonymityThresholdRequest;
use App\Services\Settings\SettingsRepository;
use Illuminate\Http\RedirectResponse;

/**
 * How many declaring officines an insurer needs before its figures show.
 *
 * The number was fixed in code until now, on the grounds that lowering it
 * de-anonymises a lone declarant. That reasoning survives as a floor rather
 * than as a locked value: the network grows, and the admin is the one who
 * knows whether five officines is prudent or merely hides the whole board.
 */
class AnonymityThresholdController extends Controller
{
    public function __construct(protected SettingsRepository $settings)
    {
        //
    }

    public function __invoke(SaveAnonymityThresholdRequest $request): RedirectResponse
    {
        $this->settings->set(
            SettingsRepository::ANONYMITY_MIN_PHARMACIES,
            $request->integer('minimum'),
        );

        return to_route('admin.insurers');
    }
}
