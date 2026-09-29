/**
 * Une valeur de graphique, ou un trou.
 *
 * unovis ne laisse un trou que sur `undefined` : il convertit `null` par
 * `Number(null)`, soit 0. Un mois retenu ou sans donnée se dessinait donc au
 * plancher et se lisait « rien » — une affirmation, alors qu'il n'y a pas de
 * chiffre du tout. Tout accesseur `y` passe par ici.
 */
export function gap(
    value: number | string | null | undefined,
): number | undefined {
    return typeof value === 'number' ? value : undefined;
}
