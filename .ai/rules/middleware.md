---
paths:
  - app/Http/Middleware/RecordAuditLog.php
---

# Middleware

## audit_logs : ni corps, ni query string, ni écran
`RecordAuditLog` (groupe web) trace les écritures, les routes `*.download` et les réponses 401/403 ; les GET de navigation sont exclus volontairement (décision du 21/10/2026). Ne jamais y ajouter le corps de requête ni la query string : les montants d'une déclaration atterriraient dans la table. Aucun écran ne lit `audit_logs` : un journal qui afficherait « l'officine X a écrit sur l'assureur Y » à un admin APhaSPB violerait l'art. 9.1 des CGU. Rétention 12 mois (`AuditLog::RETENTION_MONTHS`, `model:prune` quotidien), alignée sur la politique de confidentialité.
