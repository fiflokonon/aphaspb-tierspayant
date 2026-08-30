<?php

namespace App\Http\Controllers\Admin;

use App\Data\Period;
use App\Enums\StatsPeriod;
use App\Http\Controllers\Controller;
use App\Http\Resources\InsurerAmountsResource;
use App\Models\Insurer;
use App\Models\Pharmacy;
use App\Services\Network\NetworkStatsService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Screen 3c — how insurer delays move over a year, and what the network is owed.
 *
 * This is the advocacy view: the outstanding balance puts a figure on the
 * damage, and the twelve-month curve shows the trend against the average of the
 * agreed delays. Still no individual amount: aggregation starts at five
 * officines.
 */
class NetworkTrendsController extends Controller
{
    /** The window the canvas shows, until the admin picks otherwise. */
    protected const DEFAULT_PERIOD = StatsPeriod::LastTwelveMonths;

    public function __construct(protected NetworkStatsService $stats)
    {
        //
    }

    public function __invoke(Request $request): Response
    {
        $city = $request->string('city')->value() ?: null;
        $period = StatsPeriod::fromRequest($request->string('period')->value(), self::DEFAULT_PERIOD);

        [$from, $to] = $period->bounds();

        return Inertia::render('admin/Trends', [
            'summary' => $this->summary($from, $to, $city),
            'amounts' => $this->amounts($from, $to, $city),
            'threshold' => $this->stats->averageStandardDelayDays(),
            'period' => $period->value,
            'periodLabel' => $period->describe(),
            'periods' => StatsPeriod::options(),
            'city' => $city,
            'cities' => Pharmacy::filterableCities(),

            // The curve is the expensive read; it arrives after first paint.
            'trend' => Inertia::defer(
                fn () => $this->stats->delayTrend($from, $to, $city),
            ),
        ]);
    }

    /**
     * The four network KPIs: two service reads, one payload.
     *
     * Kept as a merge here rather than folded into the service: delays and
     * amounts are separate questions, and networkSummary() should not grow into
     * a catch-all just because one screen wants both.
     *
     * @return array<string, mixed>
     */
    protected function summary(Period $from, Period $to, ?string $city): array
    {
        return [
            ...$this->stats->networkSummary($from, $to, $city),
            ...$this->stats->aggregatedAmounts($from, $to, $city),
        ];
    }

    /**
     * Aggregated amounts per insurer, sufficient rows first, biggest debt first.
     *
     * @return list<array<string, mixed>>
     */
    protected function amounts(Period $from, Period $to, ?string $city): array
    {
        $entries = $this->stats->aggregatedByInsurer($from, $to, $city);
        $names = Insurer::query()->whereIn('id', array_keys($entries))->pluck('name', 'id');

        $rows = [];

        foreach ($entries as $insurerId => $entry) {
            $rows[] = InsurerAmountsResource::fromEntry(
                $insurerId,
                $entry,
                (string) ($names[$insurerId] ?? ''),
            );
        }

        usort($rows, function (array $a, array $b): int {
            if ($a['sufficient'] !== $b['sufficient']) {
                return $a['sufficient'] ? -1 : 1;
            }

            return ($b['outstanding'] ?? 0) <=> ($a['outstanding'] ?? 0);
        });

        return $rows;
    }
}
