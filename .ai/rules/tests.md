---
paths:
  - 'tests/**'
---

# Tests

## Http::fake garde le premier stub : ne pas en poser un global en beforeEach
`Http::fake()` empile les stubs et le **premier** enregistré qui correspond gagne. Un stub de succès posé dans un `beforeEach` masque donc tout stub d'échec qu'un test individuel voudrait exercer — le test passe en vert pour la mauvaise raison ou échoue sans rapport avec le code.

Poser le stub dans chaque test, via une fonction d'aide locale, plutôt que dans le `beforeEach`. Voir `tests/Feature/Auth/JoomlaCallbackTest.php` (`fakeJoomlaProfile()`).

Autre piège du même fichier : `->retry()` lève une `RequestException` quand toutes les tentatives échouent. Passer `throw: false` et restreindre la reprise aux `ConnectionException`, sinon un 403 déclenche des tentatives inutiles puis une exception au lieu d'une réponse.
