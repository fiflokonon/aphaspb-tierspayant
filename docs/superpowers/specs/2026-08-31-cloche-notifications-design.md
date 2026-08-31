# Cloche de notifications, et déconnexion mobile

> Suite du lot B. Les notifications de retard sont enregistrées en base
> (`docs/superpowers/specs/2026-08-31-notifications-retard-paiement-design.md`,
> §6.6) mais rien ne les affiche. Ce document couvre la surface in-app, plus
> une correction trouvée en chemin.

## 1. Problème

Le canal `database` écrit des notifications que personne ne peut lire : aucune
interface ne les expose. Le spec du lot B listait « cloche in-app » à son §9.6
sans jamais décrire l'écran, ce qui est la raison pour laquelle le plan
d'implémentation l'avait laissée de côté.

## 2. Ce que la forme du shell impose

`ConsoleLayout` se réduit à `ConsoleSidebar` + `<main><slot/></main>`. **Il n'y
a pas de barre supérieure**, et `ConsoleHeader` est un composant que chaque page
rend elle-même.

Plus contraignant : sous 1024 px, `ConsoleSidebar` **est déjà un bandeau
supérieur** — pleine largeur, bordure basse, navigation horizontale à
défilement. Empiler une seconde barre au-dessus donnerait deux bandeaux avant le
moindre contenu.

D'où le placement retenu :

| Rupture | Cloche | Raison |
|---|---|---|
| ≥ lg | `ConsoleTopBar`, sur la seule colonne de contenu | Ne traverse pas la barre latérale, dont le `sticky` est porteur (`.ai/rules/layouts.md`) |
| < lg | Dans l'en-tête de la barre latérale, à côté du logo | La barre y tient déjà lieu de bandeau supérieur |
| Mode `focus`, < lg | Masquée | L'écran de déclaration retire volontairement le chrome du téléphone |

## 3. La barre supérieure ne contient que la cloche

Le logo, le badge d'espace, l'identité et la déconnexion vivent dans la barre
latérale. Les répéter en haut créerait deux identités concurrentes, et chaque
page rend son `ConsoleHeader` juste en dessous : une barre chargée se lirait
comme un troisième en-tête.

## 4. Correction : le canal `database` est fermé aux invitations

Le spec du lot B (§6.6) affirmait qu'ajouter `'database'` au `via()` de
`PharmacyInvitation` serait gratuit, son `toArray()` étant déjà écrit. **C'est
faux, et ça ferait planter l'envoi.**

L'invitation part vers un notifiable anonyme :

```php
Notification::route('mail', $invitation->email)->notify(new PharmacyInvitation($invitation));
```

Or `DatabaseChannel::send()` fait `$notifiable->routeNotificationFor('database')->create(...)`,
et `AnonymousNotifiable::routeNotificationFor()` ne renvoie que les routes
déclarées — ici `mail` seulement, donc `null`, puis `null->create()`. C'est
structurel : une invitation vise une adresse e-mail, qui n'a pas toujours de
compte Laravel en face — la shadow table ne connaît que les comptes déjà
connectés.

**Décision :** la page lit les invitations en attente depuis le modèle
`PharmacyInvitation`, appariées sur l'e-mail de l'utilisateur connecté, comme le
fait déjà `PaymentJourneyController::pendingInvitations()` pour la modale du
tableau de bord. Même source, deux vues. Elles comptent dans la pastille sans
passer par le système de notifications.

## 5. Correction : pas de déconnexion sur téléphone

Trouvé en lisant les media queries de la barre latérale :

```css
@media (max-width: 1023px) {
    .apha-sidebar-footer { display: none; }
}
```

Ce bloc contient `ConsoleAccountFooter` — l'identité, le sélecteur d'officine et
« Se déconnecter ». **Sur téléphone, un utilisateur ne peut donc ni se
déconnecter ni changer d'officine.** C'est le cousin mobile du défaut que
`.ai/rules/layouts.md` documente côté desktop, où le pied s'était retrouvé
600 px sous la ligne de flottaison.

**Décision :** un `ConsoleAccountMenu` — menu déroulant compact portant
identité, officines et déconnexion — rendu dans l'en-tête de la barre latérale
**sous lg uniquement**. Le rail desktop garde son pied inchangé, donc aucun
risque pour le `mt-auto` que la règle protège. Le menu s'appuie sur les
composants `ui/dropdown-menu` déjà présents, et sur le même descripteur
`ConsoleAccount` que le pied.

## 6. Normalisation côté serveur

Trois formes de charge utile cohabitent : `OverduePaymentsDigest`,
`NetworkOverdueDigest`, et les invitations qui n'en sont pas une. Un
`NotificationPresenter` les ramène côté serveur à une forme unique :

```php
array{
    id: string,
    title: string,
    body: string,
    href: string|null,
    tone: 'neutral'|'warn'|'alert',
    createdAt: string,
    readAt: string|null,
    kind: 'notification'|'invitation',
}
```

Le front ne connaît donc aucun nom de classe PHP, et un nouveau type de
notification n'imposera aucun changement côté Vue.

## 7. Décisions actées

| Question | Décision |
|---|---|
| Emplacement | Barre supérieure ≥ lg, en-tête de barre latérale < lg |
| Contenu de la barre | La cloche seule |
| Marquage lu | Au clic sur chaque notification, individuellement |
| Périmètre | Retards **et** invitations en attente |
| Invitations | Lues depuis le modèle, jamais par le canal `database` |
| Compteur | Non-lues + invitations en attente, partagé via `share()` |
| Déconnexion mobile | `ConsoleAccountMenu` dans l'en-tête, sous lg |

## 8. Surface serveur

- `GET /notifications` → `notifications/Index`, paginé avec le `Pagination` et
  `App\Support\PageSize` du lot A.
- `PATCH /notifications/{notification}` → marque lue, puis retour.

Le marquage passe par `$request->user()->notifications()` et non par l'id seul :
sans ce cadrage, n'importe qui marquerait lue la notification d'un autre. Une
notification étrangère donne un 404, jamais un 403 — le 403 confirmerait son
existence.

## 9. Tests

| Objet | Cas |
|---|---|
| Compteur | non-lues + invitations, remis à zéro après lecture |
| Marquage | une notification à soi devient lue ; celle d'un autre donne 404 |
| Liste | retards et invitations dans la même liste, plus récent en tête |
| Présentation | chaque type produit un titre et un corps non vides |
| Pagination | taille de page honorée, liste blanche respectée |
| Invitations | une invitation expirée ou acceptée ne compte plus |

## 10. Hors périmètre

- Préférences de notification par utilisateur.
- Notifications temps réel (websockets) : la page se recharge, c'est assez.
- La suppression d'une notification lue ; la table croîtra, une purge planifiée
  sera à prévoir quand le volume le justifiera.
