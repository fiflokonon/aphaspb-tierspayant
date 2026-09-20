/**
 * L'état replié de la barre latérale, mémorisé par navigateur.
 *
 * Dans `localStorage` et non côté serveur : c'est une préférence d'affichage
 * propre au poste, et la faire transiter imposerait un aller-retour à chaque
 * bascule pour un réglage que personne ne veut voir synchronisé entre son
 * ordinateur et son téléphone.
 *
 * Chaque accès est enveloppé : en navigation privée, ou données de site
 * bloquées, `localStorage` lève au lieu de rendre `null`. Une barre qui
 * refuserait de s'afficher parce qu'elle ne peut pas lire sa préférence
 * serait un bien mauvais échange.
 */
const KEY = 'apha.sidebar.collapsed';

/** Replié seulement sur le marqueur exact : tout le reste ouvre déployé. */
export function readCollapsed(): boolean {
    try {
        return localStorage.getItem(KEY) === '1';
    } catch {
        return false;
    }
}

export function writeCollapsed(collapsed: boolean): void {
    try {
        localStorage.setItem(KEY, collapsed ? '1' : '0');
    } catch {
        // Préférence perdue, rien de plus : il n'y a rien à rattraper ici, et
        // laisser remonter ferait planter un clic sur « Replier ».
    }
}
