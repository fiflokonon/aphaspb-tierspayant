import { describe, expect, test } from 'vitest';
import { gap } from './chartGap';

describe('gap', () => {
    test('a missing value becomes undefined, which unovis leaves as a hole', () => {
        expect(gap(null)).toBeUndefined();
        expect(gap(undefined)).toBeUndefined();
        expect(gap('')).toBeUndefined();
    });

    test('a real figure, zero included, is kept', () => {
        expect(gap(0)).toBe(0);
        expect(gap(42.5)).toBe(42.5);
    });
});
