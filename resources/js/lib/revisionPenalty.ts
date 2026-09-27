import { formatAmount } from './fcfa';

/** Ce qu'une révision de déclaration dit de la clôture de pénalité. */
export type RevisionPenalty = {
    penaltySettlement: 'paid' | 'waived' | null;
    penaltySettlementLabel: string | null;
    penaltySettledAmount: number | null;
};

/**
 * Ce que la révision dit de la pénalité, ou null si elle n'en dit rien.
 *
 * Seule une révision où la clôture **change** en parle : une clôture se lit
 * « Pénalité payée · 32 000 F », son retour à rien « Pénalité remise en dû ».
 * Une correction de chiffres qui laisse la clôture telle quelle n'en dit
 * rien — sinon chaque révision répéterait la même ligne. La plus ancienne n'a
 * pas de précédente : elle en parle seulement si elle porte une clôture.
 *
 * Les révisions vont du plus récent au plus ancien : la précédente est donc
 * la suivante du tableau.
 */
export function revisionPenaltyLine(
    revisions: RevisionPenalty[],
    index: number,
): string | null {
    const revision = revisions[index];
    const previous = revisions[index + 1];

    const unchanged =
        previous !== undefined &&
        previous.penaltySettlement === revision.penaltySettlement &&
        previous.penaltySettledAmount === revision.penaltySettledAmount;

    if (unchanged) {
        return null;
    }

    if (revision.penaltySettlementLabel !== null) {
        return `Pénalité ${revision.penaltySettlementLabel.toLowerCase()} · ${formatAmount(revision.penaltySettledAmount)} F`;
    }

    return previous !== undefined ? 'Pénalité remise en dû' : null;
}
