import { describe, expect, it } from 'vitest';
import { formatAmount } from './fcfa';
import { revisionPenaltyLine } from './revisionPenalty';
import type { RevisionPenalty } from './revisionPenalty';

function revision(
    penaltySettlement: RevisionPenalty['penaltySettlement'],
    penaltySettledAmount: number | null = null,
): RevisionPenalty {
    return {
        penaltySettlement,
        penaltySettlementLabel:
            penaltySettlement === 'paid'
                ? 'Payée'
                : penaltySettlement === 'waived'
                  ? 'Annulée'
                  : null,
        penaltySettledAmount,
    };
}

const paid = `Pénalité payée · ${formatAmount(32_000)} F`;

describe('revisionPenaltyLine', () => {
    it('says nothing on a correction that leaves the settlement untouched', () => {
        // Du plus récent au plus ancien : la clôture date de la révision 1.
        const revisions = [
            revision('paid', 32_000),
            revision('paid', 32_000),
            revision(null),
        ];

        expect(revisionPenaltyLine(revisions, 0)).toBeNull();
        expect(revisionPenaltyLine(revisions, 1)).toBe(paid);
        expect(revisionPenaltyLine(revisions, 2)).toBeNull();
    });

    it('speaks again when the outcome or the amount changes', () => {
        const revisions = [
            revision('waived', 24_000),
            revision('paid', 24_000),
            revision('paid', 32_000),
        ];

        expect(revisionPenaltyLine(revisions, 0)).toBe(
            `Pénalité annulée · ${formatAmount(24_000)} F`,
        );
        expect(revisionPenaltyLine(revisions, 1)).toBe(
            `Pénalité payée · ${formatAmount(24_000)} F`,
        );
        // La plus ancienne n'a pas de précédente : elle parle si elle est close.
        expect(revisionPenaltyLine(revisions, 2)).toBe(paid);
    });

    it('says a closure was lifted, once', () => {
        const revisions = [
            revision(null),
            revision(null),
            revision('paid', 32_000),
        ];

        expect(revisionPenaltyLine(revisions, 1)).toBe('Pénalité remise en dû');
        expect(revisionPenaltyLine(revisions, 0)).toBeNull();
    });
});
