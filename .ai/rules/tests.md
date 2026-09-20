---
paths:
  - 'tests/**'
---

# Tests

## Http::fake garde le premier stub : ne pas en poser un global en beforeEach
`Http::fake()` empile les stubs et le **premier** enregistré qui correspond gagne. Un stub de succès posé dans un `beforeEach` masque donc tout stub d'échec qu'un test individuel voudrait exercer — le test passe en vert pour la mauvaise raison ou échoue sans rapport avec le code.

Poser le stub dans chaque test, via une fonction d'aide locale, plutôt que dans le `beforeEach`. Voir `tests/Feature/Auth/JoomlaCallbackTest.php` (`fakeJoomlaProfile()`).

Autre piège du même fichier : `->retry()` lève une `RequestException` quand toutes les tentatives échouent. Passer `throw: false` et restreindre la reprise aux `ConnectionException`, sinon un 403 déclenche des tentatives inutiles puis une exception au lieu d'une réponse.

## DeclarationFactory remplit toujours invoice_deposited_on — le passer à null ne suffit pas
`DeclarationFactory::configure()` pose `invoice_deposited_on` dans son `afterMaking` **dès qu'il est nul**, en l'ancrant sur la fin du mois déclaré. Passer `'invoice_deposited_on' => null` en attribut ne produit donc **pas** l'état visé : la fabrique le remplit derrière.

Pour tester une déclaration sans date de dépôt — état que la production connaît, et que `OverduePaymentsService` et `InsurerPenaltyAggregates` excluent tous deux par `whereNotNull` —, créer normalement puis effacer la colonne par requête :

```php
$d = Declaration::factory()->create([...]);
DB::table('declarations')->where('id', $d->id)->update(['invoice_deposited_on' => null]);
```

Même famille de piège que `delay_days`, que le hook `saving` régénère à chaque enregistrement : un test qui force l'un ou l'autre en attribut décrit un état que la production ne produit jamais, et passe alors par une porte dérobée. Le lot A s'y est fait prendre une fois (`LongestDelay` et les mois partiellement réglés) ; le lot B une seconde.
