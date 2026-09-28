import { describe, expect, it } from 'vitest';
import { withheldExplanation } from './withheld';

describe('withheldExplanation', () => {
    it('never states an exact count, only the threshold', () => {
        expect(withheldExplanation('too-few', 5)).toBe(
            'moins de 5 officines déclarantes sur ce périmètre',
        );
    });

    it('explains the city partition rule', () => {
        expect(withheldExplanation('city-share', 7)).toBe(
            'hors filtre ville, les villes non publiées y pèsent moins de 7 officines',
        );
    });
});
