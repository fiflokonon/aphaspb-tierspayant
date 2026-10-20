<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveDeclarationRangeRequest;
use App\Services\Settings\SettingsRepository;
use Illuminate\Http\RedirectResponse;

/**
 * Jusqu'où les officines peuvent déclarer, et ce qu'on réclame aux nouvelles.
 *
 * Remplace la fenêtre glissante de douze mois : avec elle, une officine
 * inscrite un an après le lancement se voyait réclamer un an entier, et le
 * réseau perdait un mois d'historique déclarable chaque mois.
 */
class DeclarationRangeController extends Controller
{
    public function __construct(protected SettingsRepository $settings)
    {
        //
    }

    public function __invoke(SaveDeclarationRangeRequest $request): RedirectResponse
    {
        $this->settings->set(SettingsRepository::DECLARATION_FLOOR, $request->floor()->toOrdinal());
        $this->settings->set(SettingsRepository::CATCH_UP_MONTHS, $request->integer('catch_up_months'));

        return back();
    }
}
