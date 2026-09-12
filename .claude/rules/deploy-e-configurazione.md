---
paths:
  - "scripts/**"
  - ".github/workflows/**"
  - "config/**"
---

# Trappole: deploy e configurazione

- **`scripts/deploy_prod.sh` esegue `php artisan optimize`, che congela la configurazione.** Se il
  `.env` del server perde `WM_TRAIL_REGISTRY_ENABLED`, route e Nova resource del Catasto spariscono
  mentre la tabella resta piena di dati, e il SUS riceve 404. **Se il Catasto smette di rispondere
  senza motivo apparente, la causa va cercata qui per prima.** Una verifica automatica al deploy è
  stata valutata e scartata: rischio accettato consapevolmente (oc:8492)
- **La licenza Nova è scaduta**: dalla 5.8.0 in poi le release rispondono HTTP 402. Sia forestas
  sia wm-package vanno tenuti su `laravel/nova 5.7.6` — `composer.json` esclude esplicitamente
  `5.7.7`. Il rinnovo diventa obbligatorio prima di passare a Laravel 13 (oc:8469)
- **Il gate `publish-missing-migrations --dry-run` in CI** va invocato con `--with=trail_registry`
  finché il dominio è acceso, e va aggiornato se il dominio si spegne: il dettaglio è in
  [docs/knowledge/gate-pubblicazione-migrazioni.md](../../docs/knowledge/gate-pubblicazione-migrazioni.md) (oc:8492)
- **`elasticsearch-init` è un container curl one-shot** che imposta la password di `kibana_system`
  via API (Elasticsearch non supporta variabili d'ambiente per quell'utente) e si rimuove da solo.
  La sequenza di avvio è `elasticsearch` → `elasticsearch-init` → `kibana`; `scout-init` (solo
  `local.compose.yml`) esegue `scout:import` sui modelli del package quando Elasticsearch e il
  database sono pronti
