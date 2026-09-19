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

/**
 * Un montant de tableau, avec zéro rendu comme zéro et null comme un tiret.
 *
 * formatFcfa() rend une chaîne vide dès que la valeur est nulle ou négative,
 * ce qui convient à un champ de saisie mais pas à une cellule : « 0 » dit
 * « rien encaissé », une cellule vide ne dit rien. Le tiret est réservé à
 * l'absence de donnée — typiquement une clause de pénalité jamais convenue.
 */
export function formatAmount(value: number | null): string {
    if (value === null) {
        return '—';
    }

    return value === 0 ? '0' : formatFcfa(value);
}
