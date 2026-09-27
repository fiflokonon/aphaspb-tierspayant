import { describe, expect, test } from 'vitest';
import type {
    PenaltyLedger,
    PenaltyLedgerMonth,
    PenaltyLedgerSeries,
} from '@/types/aphaspb';
import { isPenaltyView } from '@/types/aphaspb';
import { penaltyChartRows, visibleSeries } from './penaltySeries';

function month(
    key: string,
    values: Partial<PenaltyLedgerMonth> = {},
): PenaltyLedgerMonth {
    return {
        month: key,
        label: key,
        current: false,
        future: false,
        accrued: 0,
        accruedCumulative: 0,
        declared: 0,
        accruedPaid: 0,
        accruedWaived: 0,
        accruedDue: 0,
        declaredDue: 0,
        withheld: false,
        ...values,
    };
}

function series(
    insurerId: number | null,
    months: PenaltyLedgerMonth[],
): PenaltyLedgerSeries {
    return { insurerId, name: `S${insurerId}`, months };
}

const ledger: PenaltyLedger = {
    insurers: [
        series(1, [
            month('2026-05', { accrued: 10, declared: 70, accruedDue: 4 }),
        ]),
        series(2, [
            month('2026-05', { accrued: 5, declared: 0, accruedDue: 5 }),
        ]),
    ],
    total: series(null, [
        month('2026-05', { accrued: 15, declared: 70, accruedDue: 9 }),
    ]),
    maskedInsurers: 0,
};

describe('visibleSeries', () => {
    test('every insurer and the total when nothing is filtered', () => {
        const shown = visibleSeries(ledger, null);

        expect(shown.series.map((one) => one.insurerId)).toEqual([1, 2]);
        expect(shown.total?.insurerId).toBeNull();
    });

    test('one insurer alone, without the total that would repeat it', () => {
        const shown = visibleSeries(ledger, 2);

        expect(shown.series.map((one) => one.insurerId)).toEqual([2]);
        expect(shown.total).toBeNull();
    });

    test('nothing at all while the deferred payload is on its way', () => {
        expect(visibleSeries(undefined, null)).toEqual({
            series: [],
            total: null,
        });
    });
});

describe('penaltyChartRows', () => {
    test('picks the figure of the chosen clock', () => {
        const accrued = penaltyChartRows(
            ledger.insurers,
            ledger.total,
            'accrued',
        );
        const declared = penaltyChartRows(
            ledger.insurers,
            ledger.total,
            'declared',
        );

        expect(accrued[0]).toMatchObject({ s0: 10, s1: 5, total: 15 });
        expect(declared[0]).toMatchObject({ s0: 70, s1: 0, total: 70 });
    });

    test('the due view reads what remains due once paid and waived are out', () => {
        const due = penaltyChartRows(ledger.insurers, ledger.total, 'due');

        expect(due[0]).toMatchObject({ s0: 4, s1: 5, total: 9 });
    });

    test('a withheld month stays a gap in the due view too', () => {
        const rows = penaltyChartRows(
            [
                series(1, [
                    month('2026-06', { withheld: true, accruedDue: null }),
                ]),
            ],
            null,
            'due',
        );

        expect(rows[0]?.s0).toBeNull();
    });

    test('a withheld or future month is a gap, never a zero', () => {
        const rows = penaltyChartRows(
            [
                series(1, [
                    month('2026-06', { withheld: true, accrued: null }),
                    month('2026-10', { future: true, accrued: null }),
                ]),
            ],
            null,
            'accrued',
        );

        expect(rows.map((row) => row.s0)).toEqual([null, null]);
        expect(rows.map((row) => row.total)).toEqual([null, null]);
    });

    test('the months come from the total, or from the first series without one', () => {
        expect(penaltyChartRows([], ledger.total, 'accrued')).toHaveLength(1);
        expect(penaltyChartRows([], null, 'accrued')).toEqual([]);
    });
});

describe('isPenaltyView', () => {
    test('knows the three clocks and nothing else', () => {
        expect(['accrued', 'declared', 'due'].every(isPenaltyView)).toBe(true);
        expect(isPenaltyView('paid')).toBe(false);
    });
});
