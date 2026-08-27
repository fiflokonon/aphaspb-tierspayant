/**
 * The XOF has no subunit, so amounts are whole numbers throughout.
 */

const THIN_NBSP = ' ';

/** Group thousands the way the canvas does: 1 240 000. */
export function formatFcfa(value: number): string {
    if (!Number.isFinite(value) || value <= 0) {
        return '';
    }

    return Math.trunc(value)
        .toString()
        .replace(/\B(?=(\d{3})+(?!\d))/g, THIN_NBSP);
}

/**
 * Keep only digits, so a pasted "1 240 000 FCFA" or "1.240.000" still lands.
 */
export function parseFcfa(input: string): number {
    const digits = input.replace(/\D/g, '');

    return digits === '' ? 0 : Number.parseInt(digits, 10);
}
