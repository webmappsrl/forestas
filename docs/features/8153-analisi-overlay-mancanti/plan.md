> Ticket: oc:8153 — Analisi overlay mancanti (figlio di oc:7605)

# Piano — Analisi overlay mancanti

Questo ticket è di **analisi pura**: nessuna modifica al codice. Il piano documenta i passi dell'analisi e i deliverable prodotti. L'implementazione è tracciata su oc:7605.

---

## Fase 1 — Esplorazione codebase

- [x] Identificare come gli overlay sono modellati nel codice (`FeatureCollection` model, `config_overlays` JSONB, `AppConfigService`, `ConfigOverlaysResolver`)
- [x] Leggere `wm-package/src/Services/Models/App/AppConfigService.php` → sezione overlay (righe 428–490)
- [x] Leggere `wm-package/src/Nova/Flexible/Resolvers/ConfigOverlaysResolver.php`
- [x] Leggere `wm-package/src/Models/FeatureCollection.php` → metodo `getUrl()`, modes, observer
- [x] Leggere `wm-package/src/Nova/FeatureCollection.php` → campi Nova, `GenerateFeatureCollectionAction`
- [x] Verificare la migrazione `2026_04_01_110002_..._add_overlays_fields_to_apps_table.php`

## Fase 2 — Rilevamento stato attuale

- [x] Interrogare DB locale: `apps.config_overlays` → `{"OVERLAYS": []}` (vuoto in locale)
- [x] Interrogare DB locale: `feature_collections` → 0 record
- [x] Chiamare API dev: `GET /api/app/webapp/1/config` → `MAP.controls.overlays` con titolo + "Aree Catastali"
- [x] Chiamare API UAT: `GET /api/app/webapp/1/config` → `MAP.controls.overlays` vuoto `[]`

## Fase 3 — Confronto con Geohub

- [x] Identificare app Forestas in Geohub: `GET /api/v2/app/all` → app id=32 "Sardegna Sentieri"
- [x] Chiamare API Geohub: `GET /api/v2/app/webmapp/32/config.json` → 5 overlay (titolo + 4 FC)
- [x] Mappare differenze:
  - Dev: mancano Aree geografiche, Aree forestali, Aree montane, Aree no caccia
  - UAT: mancano tutti e 5 gli overlay

## Fase 4 — Documentazione

- [x] Scrivere `docs/features/8153-analisi-overlay-mancanti/overview.md` con stato attuale, overlay mancanti per ambiente, pattern da seguire, rischi e raccomandazione (seeder)
- [x] Revisione adversariale dell'overview (5 assi: assunzioni fragili, rischi architetturali, blind spot, worst case, rollback)
- [x] Aggiornare overview con correzioni emerse dalla challenge
- [x] Scrivere `docs/features/8153-analisi-overlay-mancanti/notes.md`
- [x] Aggiornare `CLAUDE.md` con sezione "Feature disponibili" e "Decisioni architetturali"

---

## Output dell'analisi (input per oc:7605)

| Deliverable | Contenuto |
|---|---|
| `overview.md` | Stato attuale, overlay mancanti per ambiente, tabella stili/GeoJSON, requisiti implementativi, rischi, raccomandazione seeder |
| Geohub config | `GET https://geohub.webmapp.it/api/v2/app/webmapp/32/config.json` → SVG icone, colori, ordine |

---

## Decisione aperta da prendere prima dell'implementazione (oc:7605)

**Seeder idempotente vs configurazione manuale via Nova.**
**Raccomandazione: seeder idempotente** (motivazione tecnica in `overview.md` → sezione Raccomandazione). Da decidere con il team prima di iniziare l'implementazione. Nota: un seeder con upload MinIO richiede rete disponibile e bucket `wmfe` configurato nell'ambiente target.
