/**
 * Why a network figure is withheld, as the server says it.
 *
 * `too-few`: the figure itself rests on fewer officines than the threshold.
 * `city-share`: unfiltered by city, the figure minus the published cities
 * would give back cities (and officines without a city) resting on too few.
 * Neither ever carries the exact count: « moins de N » is all that shows.
 */
export type WithheldReason = 'too-few' | 'city-share';

/** The one-line explanation under a withheld figure or row. */
export function withheldExplanation(
    reason: WithheldReason | null,
    required: number,
): string {
    return reason === 'city-share'
        ? `hors filtre ville, les villes non publiées y pèsent moins de ${required} officines`
        : `moins de ${required} officines déclarantes sur ce périmètre`;
}
