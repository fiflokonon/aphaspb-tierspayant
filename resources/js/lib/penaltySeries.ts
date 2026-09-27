import type {
    PenaltyLedger,
    PenaltyLedgerMonth,
    PenaltyLedgerSeries,
    PenaltyView,
} from '@/types/aphaspb';

/** Une ligne du graphique : un mois, une colonne `s{n}` par série affichée. */
export type PenaltyChartRow = {
    index: number;
    label: string;
    total: number | null;
    [series: string]: number | string | null;
};

/**
 * Ce que la carte affiche pour un filtre assureur donné.
 *
 * Restreinte à un assureur, la série totale disparaît : elle ne ferait que
 * répéter celle de l'assureur.
 */
export function visibleSeries(
    ledger: PenaltyLedger | undefined,
    insurerId: number | null,
): { series: PenaltyLedgerSeries[]; total: PenaltyLedgerSeries | null } {
    if (ledger === undefined) {
        return { series: [], total: null };
    }

    return {
        series: ledger.insurers.filter(
            (one) => insurerId === null || one.insurerId === insurerId,
        ),
        total: insurerId === null ? ledger.total : null,
    };
}

/**
 * Le chiffre d'un mois selon l'horloge choisie.
 *
 * Un mois retenu ou futur arrive déjà à null du serveur, et le reste : tracé à
 * zéro, il se lirait « rien n'a couru », ce qui est une affirmation.
 */
function pick(
    month: PenaltyLedgerMonth | undefined,
    view: PenaltyView,
): number | null {
    if (month === undefined) {
        return null;
    }

    switch (view) {
        case 'accrued':
            return month.accrued;
        case 'declared':
            return month.declared;
        case 'due':
            return month.accruedDue;
    }
}

export function penaltyChartRows(
    series: PenaltyLedgerSeries[],
    total: PenaltyLedgerSeries | null,
    view: PenaltyView,
): PenaltyChartRow[] {
    const months = (total ?? series[0])?.months ?? [];

    return months.map((month, index) => {
        const row: PenaltyChartRow = {
            index,
            label: month.label,
            total: total ? pick(total.months[index], view) : null,
        };

        series.forEach((one, position) => {
            row[`s${position}`] = pick(one.months[index], view);
        });

        return row;
    });
}
