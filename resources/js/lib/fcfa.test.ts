import { describe, expect, it } from 'vitest';
import { formatAmount, formatFcfa } from './fcfa';

describe('formatAmount', () => {
    it('renders null as a dash, for an absent clause', () => {
        expect(formatAmount(null)).toBe('—');
    });

    it('renders zero as zero, where formatFcfa renders nothing', () => {
        expect(formatFcfa(0)).toBe('');
        expect(formatAmount(0)).toBe('0');
    });

    it('groups thousands like formatFcfa above zero', () => {
        expect(formatAmount(1_240_000)).toBe(formatFcfa(1_240_000));
    });
});
