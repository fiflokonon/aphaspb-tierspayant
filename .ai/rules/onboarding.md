---
paths:
  - 'app/Http/Controllers/Onboarding/**'
---

# Onboarding

## owner_name vient de Joomla, il ne se saisit pas
`pharmacies.owner_name` a deux écrivains, tous deux serveur : `PharmacyProfileController::store()` le pose depuis `$request->user()->name` à la création, et `SyncTitulaireName` (appelé par `JoomlaCallbackController`) le rafraîchit à chaque connexion du titulaire. `SavePharmacyProfileRequest` ne valide plus ce champ, donc un `owner_name` posté est ignoré.

Ne pas le remettre comme champ de formulaire : le désactiver côté Vue ne protège rien, la valeur repartirait du navigateur. L'écran d'onboarding l'affiche en lecture seule depuis `auth.user.name`.

La synchronisation ne touche que `ownedPharmacies()` (pivot `role = owner`) : un simple membre qui se connecte ne renomme pas le titulaire de l'officine. Les deux comportements sont verrouillés dans `tests/Feature/Auth/JoomlaCallbackTest.php` et `tests/Feature/Onboarding/PharmacyProfileTest.php`, chacun vérifié par contrôle négatif.

## La ville d'inscription est une liste fermée, la région s'en déduit
Depuis le 15/10/2026, `SavePharmacyProfileRequest` exige une ville présente dans `cities` (`exists:cities,name`) : la région (`cities.region_id`, gérée dans « Régions pharmaceutiques ») se déduit de la ville et n'est jamais stockée sur l'officine (`Pharmacy::regionName()`). L'écran affiche la région en lecture seule depuis la prop `cities` ({name, region}). Les officines inscrites avant gardent leur ville libre. Une ville n'appartient qu'à une région : la colonne unique `region_id` le garantit, et `SaveRegionRequest` refuse une ville déjà prise. Le N° ONPB a été supprimé (colonne comprise), ne pas le réintroduire.
