<?php

namespace App\Http\Controllers\Admin;

use App\Data\InsufficientData;
use App\Data\InsurerIndicators;
use App\Data\Period;
use App\Http\Controllers\Controller;
use App\Http\Resources\InsurerIndicatorsResource;
use App\Models\Insurer;
use App\Models\Pharmacy;
use App\Services\Network\NetworkStatsService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Screen 2a — the report view: one row of indicators per insurer.
 *
 * Reads exclusively through NetworkStatsService, which is what guarantees no
 * individual declaration, amount or private note can surface here.
 */
class NetworkStatsController extends Controller
{
    public function __construct(protected NetworkStatsService $stats)
    {
        //
    }

    public function __invoke(Request $request): Response
    {
        $city = $request->string('city')->value() ?: null;

        [$from, $to] = Period::currentQuarter();

        $entries = $this->stats->perInsurer($from, $to, $city);
        $names = Insurer::query()->whereIn('id', array_keys($entries))->pluck('name', 'id');

        return Inertia::render('admin/Network', [
            'indicators' => $this->sorted($entries, $names),
            'summary' => $this->stats->networkSummary($from, $to, $city),
            'period' => $this->periodLabel($from),
            'city' => $city,
            'cities' => Pharmacy::query()
                ->whereNotNull('city')
                ->distinct()
                ->orderBy('city')
                ->pluck('city')
                ->all(),
        ]);
    }

    /**
     * Sufficient insurers first, by rising delay; the explained states after.
     *
     * @param  array<int, InsurerIndicators|InsufficientData>  $entries
     * @param  Collection<int, string>  $names
     * @return list<array<string, mixed>>
     */
    protected function sorted(array $entries, $names): array
    {
        $rows = [];

        foreach ($entries as $insurerId => $entry) {
            $rows[] = InsurerIndicatorsResource::fromEntry(
                $insurerId,
                $entry,
                (string) ($names[$insurerId] ?? ''),
            );
        }

        usort($rows, function (array $a, array $b): int {
            if ($a['sufficient'] !== $b['sufficient']) {
                return $a['sufficient'] ? -1 : 1;
            }

            return ($a['averageDelayDays'] ?? 0) <=> ($b['averageDelayDays'] ?? 0);
        });

        return $rows;
    }

    /**
     * Render the quarter the way the canvas does: « 3ᵉ trimestre 2026 ».
     */
    protected function periodLabel(Period $from): string
    {
        $quarter = intdiv($from->month - 1, 3) + 1;
        $suffix = $quarter === 1 ? 'er' : 'e';

        return "{$quarter}{$suffix} trimestre {$from->year}";
    }
}
