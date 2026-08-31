# Notifications de retard de paiement

> Lot B des retours de recette du 31/08/2026. Le lot A — graphiques
> personnalisables et pagination réglable — fait l'objet de la PR #2 et n'est
> pas couvert ici.

## 1. Problème

L'application mesure les retards de paiement mais ne les signale à personne.
Une officine qui ne consulte pas son tableau de bord ignore qu'une facture a
dépassé le délai convenu avec son assureur, et l'APhaSPB ne voit rien venir
tant qu'elle n'ouvre pas l'écran réseau.

Rien n'existe côté envoi : pas de table `notifications`, pas de commande, une
seule classe de notification (`PharmacyInvitation`, canal `mail` uniquement).
Il existe en revanche un ordonnanceur, avec une tâche quotidienne déclarée dans
`routes/console.php`, et la file est configurée sur `database` avec la table
`jobs` en place.

## 2. Le référentiel : dépôt → paiement, par assureur

C'est la décision qui commande tout le reste, et elle était déjà prise ailleurs
dans le code.

`Declaration::deriveDelayDays()` calcule `paid_on − invoice_deposited_on`, et
`NetworkStatsService::WITHIN_STANDARD_DELAY_SUM` juge la conformité en
comparant ce délai à `insurers.standard_delay_days` :

```sql
SUM(CASE WHEN status IN ('paid', 'partial')
         AND delay_days <= insurers.standard_delay_days
    THEN 1 ELSE 0 END) as within_threshold
```

L'alerte doit se compter sur **la même horloge**, faute de quoi l'e-mail et
l'écran réseau se contrediraient sur la même facture.

Une piste a été écartée en cours de conception : ancrer le retard sur la **fin
du mois déclaré**, comme le fait `PharmacyStatsService::outstandingByMonth()`
pour les tranches d'ancienneté du tableau de bord. Ces tranches mesurent
l'ancienneté d'un encours, pas le respect d'un délai contractuel. Les deux
horloges sont légitimes, mais elles répondent à deux questions différentes, et
une alerte de retard relève de la seconde.

### 2.1 Définition retenue

```
en retard  ⟺  amount_invoiced > amount_received
           ∧  status ≠ rejected
           ∧  invoice_deposited_on ≠ null
           ∧  today − invoice_deposited_on > insurers.standard_delay_days
```

`rejected` est exclu : une facture refusée n'est pas un retard de paiement mais
un litige, et la relancer comme un impayé serait un contresens.

Le seuil est strict (`>`), pour s'aligner sur `delay_days <= standard_delay_days`
qui définit la conformité : une facture payée pile au délai standard est
conforme, donc une facture non payée pile au délai standard n'est pas encore en
retard.

## 3. Décisions actées

| Question | Décision |
|---|---|
| Ancrage du retard | Date de dépôt + `insurers.standard_delay_days` |
| Factures rejetées | Exclues |
| Date de dépôt manquante | `invoice_deposited_on` passe en `required` |
| Destinataires côté officine | Rôles `Owner` et `Admin` |
| Cadence | Récapitulatif hebdomadaire groupé, un envoi par officine |
| Digest admin | Hebdomadaire, agrégé par assureur, jamais nominatif |
| Canaux | `mail` + `database` |
| État « déjà prévenu » | Aucune table de suivi — voir §5 |

## 4. Prérequis : la date de dépôt devient obligatoire

`SaveDeclarationRequest` laisse aujourd'hui `invoice_deposited_on` en
`nullable`, et ne l'exige que si le statut est réglé (`withValidator()`). Une
facture impayée — exactement la population que l'alerte vise — peut donc être
enregistrée sans date de dépôt, et n'a alors aucun retard calculable.

La règle passe à `required`. Le champ est déjà affiché sur **toutes** les
déclarations (`Declare.vue`, le `DateField` du dépôt est inconditionnel ; seul
`paid_on` est masqué quand rien n'a été payé), donc l'écran ne change pas.
`withValidator()` cesse d'avoir à réclamer la date de dépôt et ne garde que la
règle sur `paid_on`.

### 4.1 Deux effets de bord à assumer

**Les lignes existantes sans date restent hors périmètre.** Le `required` ne
s'applique qu'aux enregistrements futurs. Les anciennes factures sans date de
dépôt n'ont pas de retard calculable et ne déclencheront rien.

> Sur la base de démo locale, les 912 déclarations ont toutes été créées le
> 27/08/2026, le jour où la migration a ajouté les deux colonnes en `nullable` :
> leurs dates sont nulles quel que soit le statut, y compris sur 670
> déclarations payées. C'est un artefact de reprise, pas une propriété du
> domaine. **Le volume réel en production n'est pas connu et doit être mesuré
> avant de décider s'il faut une reprise de données.**

**Rouvrir une vieille déclaration forcera à saisir la date.** L'historique met
un lien d'édition sur chaque ligne. L'effet est plutôt sain — les données se
complètent au fil des corrections — mais c'est un changement de comportement
sur un écran existant, qui doit avoir ses propres tests.

## 5. Où vit l'état « déjà prévenu »

Trois options ont été pesées.

| | Mécanisme | Verdict |
|---|---|---|
| **A** | Aucune table de suivi. La commande recalcule l'état courant ; l'anti-doublon est une lecture de la table `notifications` sur la semaine ISO en cours. | **Retenu** |
| B | Table `declaration_overdue_alerts` (`declaration_id`, `alerted_at`). | Nécessaire seulement pour des alertes facture par facture, que le digest groupé rend inutiles. |
| C | Colonne `overdue_notified_at` sur `declarations`. | Met de l'état de livraison dans la table métier. `.ai/rules/models.md` met déjà en garde contre les colonnes qui deviennent une seconde source de vérité. |

Le choix d'un récapitulatif hebdomadaire est ce qui rend A suffisant : il n'y a
pas d'escalade par facture à mémoriser, seulement une question binaire — cette
officine a-t-elle déjà reçu son digest cette semaine ?

## 6. Architecture cible

### 6.1 Service — `OverduePaymentsService`

Une classe dédiée plutôt qu'une méthode de plus sur `PharmacyStatsService` :
celui-ci répond à « quels sont mes chiffres », le retard relève de « qui faut-il
relancer ». Les deux services existants sont déjà séparés par une frontière de
confidentialité (`PharmacyStatsService` ne voit qu'une officine nommée,
`NetworkStatsService` n'agrège jamais un nom), et ce troisième objet répond à
une troisième question.

```php
/** @return list<OverdueLine> */
public function forPharmacy(Pharmacy $pharmacy): array;

/** @return Collection<int, Pharmacy> Celles ayant au moins une facture en retard. */
public function pharmaciesWithOverdue(): Collection;

/** @return list<InsurerOverdueTotals> Agrégat réseau, seuil d'anonymat appliqué. */
public function networkTotals(): array;
```

`OverdueLine` porte : assureur, mois déclaré, `invoice_deposited_on`, ancienneté
en jours, délai standard de l'assureur, reste dû.

### 6.2 Commande — `declarations:notify-overdue`

Planifiée dans `routes/console.php` avec `withoutOverlapping()`, sur le
modèle de la purge quotidienne déjà en place.

**Lundi 07:00**, avec `->timezone('Africa/Porto-Novo')` : `app.timezone` vaut
`UTC`, et une relance envoyée à 07:00 UTC arriverait à 08:00 au Bénin — le
choix est sans conséquence technique mais doit être explicite plutôt que subi.
Le lundi place le récapitulatif en début de semaine ouvrée, quand une officine
peut encore agir dessus.

Options :

- `--dry-run` : affiche ce qui partirait, sans rien envoyer. Indispensable pour
  mesurer le volume avant le premier envoi réel.
- `--force` : ignore l'anti-doublon hebdomadaire, pour un rattrapage manuel.

### 6.3 Notifications

**`OverduePaymentsDigest`** → titulaire et gestionnaires de l'officine.
Canaux `mail` + `database`. Une ligne par facture, triée par ancienneté
décroissante : assureur, mois déclaré, ancienneté, délai standard, reste dû.
Un total en tête.

**`NetworkOverdueDigest`** → admins réseau. Canaux `mail` + `database`.
Agrégé **par assureur uniquement**.

### 6.4 Le seuil d'anonymat s'applique au digest admin

Le CDC n'autorise l'agrégation qu'à partir de cinq officines déclarantes, et le
code fait respecter la règle (`InsurerIndicatorsResource`, drapeau
`sufficient`). Un digest qui nommerait les officines en retard contournerait
cette protection par la porte de derrière.

Le digest admin s'en tient donc à « N factures au-delà du délai chez l'assureur
X, M FCFA », et ne nomme jamais une officine à côté d'un montant. Les assureurs
sous le seuil sont omis, comme sur les écrans réseau.

### 6.5 Trouver les admins réseau

Les admins ne sont pas un rôle en base mais un groupe Joomla
(`config('joomla.groups.admin')`, vérifié par `User::hasAnyJoomlaGroup()`). La
requête passe par `whereJsonContains('joomla_groups', $group)` — vérifié
fonctionnel sur SQLite comme sur MySQL.

> Réserve : la shadow table `users` ne connaît que les comptes déjà connectés au
> moins une fois à Laravel. **Un seul admin réseau y figure aujourd'hui.** Le
> digest ne touchera donc qu'une personne tant que les autres ne se sont pas
> connectés. Ce n'est pas un défaut de cette fonctionnalité mais une propriété
> du modèle d'authentification ; il faut le savoir avant de conclure que
> l'envoi ne marche pas.

### 6.6 Table `notifications`

Créée par `php artisan make:notifications-table`. Elle sert le canal
`database`, c'est-à-dire la cloche in-app, et porte aussi l'anti-doublon
hebdomadaire du §5.

`PharmacyInvitation` possède déjà un `toArray()` inutilisé : ajouter
`'database'` à son `via()` lui donne la cloche sans travail supplémentaire.
Facultatif, à confirmer au moment du plan.

## 7. Ce qui bloquera en production

Deux points d'infrastructure, hors périmètre du code mais bloquants à la mise
en service :

- **`MAIL_MAILER=log`.** Aucun e-mail ne part ; ils atterrissent dans les logs.
- **Les notifications sont `ShouldQueue`** et `QUEUE_CONNECTION=database`. Sans
  worker en marche, elles s'empilent dans `jobs` sans jamais partir. La file est
  vide et propre aujourd'hui, donc rien n'est coincé.

Aucun des deux n'empêche d'écrire et de tester le code : `Notification::fake()`
couvre la suite de tests.

## 8. Tests

| Objet | Cas |
|---|---|
| Définition | conforme au seuil pile, en retard un jour après, deux assureurs aux délais différents dans le même envoi |
| Exclusions | rejetées, sans date de dépôt, soldées |
| Groupement | une officine à sept factures reçoit un envoi, pas sept |
| Destinataires | `Owner` et `Admin` notifiés, `Member` non |
| Anti-doublon | deux exécutions la même semaine → un seul envoi ; la semaine suivante → un nouvel envoi ; `--force` outrepasse |
| `--dry-run` | n'envoie rien, `Notification::fake()` reste vierge |
| Digest admin | agrégé par assureur, aucun nom d'officine dans la charge utile, assureurs sous le seuil d'anonymat omis |
| Date de dépôt | déclaration refusée sans `invoice_deposited_on`, y compris impayée |

`.ai/rules/tests.md` s'applique : ne pas poser de stub global en `beforeEach`.

## 9. Ordre d'exécution

1. `invoice_deposited_on` en `required` + tests de l'écran de déclaration.
2. Table `notifications`.
3. `OverduePaymentsService` + tests de la définition du retard.
4. `OverduePaymentsDigest` + commande + planification.
5. `NetworkOverdueDigest` + seuil d'anonymat.
6. Cloche in-app.

Les étapes 1 à 3 sont livrables seules et n'envoient rien : elles peuvent être
relues sans risque d'envoi accidentel.

## 10. Hors périmètre

- Préférences de désabonnement par utilisateur. À rouvrir si le volume gêne.
- Relance par facture et escalade. Écartées avec le choix du digest groupé.
- Reprise des déclarations existantes sans date de dépôt : à décider une fois le
  volume de production mesuré (§4.1).
- Configuration du mailer et du worker de file (§7).
