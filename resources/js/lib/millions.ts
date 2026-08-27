/**
 * Render an FCFA amount the way the canvas does: 56,2 M — 6,84 Md.
 *
 * Beyond a thousand million the canvas switches to milliards, because a network
 * total in millions stops being readable at that scale.
 */
export function formatMillions(value: number): string {
    const millions = value / 1_000_000;

    if (Math.abs(millions) >= 1_000) {
        return `${round(millions / 1_000, 2)} Md`;
    }

    if (Math.abs(millions) >= 1) {
        return `${round(millions, 1)} M`;
    }

    return `${round(millions, 2)} M`;
}

/** The scale alone, without a unit, for chart axes. */
export function toMillions(value: number, decimals = 1): string {
    return round(value / 1_000_000, decimals);
}

function round(value: number, decimals: number): string {
    return value
        .toLocaleString('fr-FR', {
            minimumFractionDigits: 0,
            maximumFractionDigits: decimals,
        })
        .replace('.', ',');
}
