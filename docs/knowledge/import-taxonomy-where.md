# Identifier di `TaxonomyWhere`

Il lavoro vive tutto in `wm-package` (vedi
`wm-package/docs/features/8469-fix-identifier-taxonomy-where/`). Qui resta ciò che serve sapendo di
stare su Forestas. (oc:8469)

## Stato attuale

- L'identifier di `TaxonomyWhere` deriva da `properties['source']` più l'id della sorgente
  (`osmfeatures-r276369`, `osm2cai-142`), **mai dal nome**: i nomi da OSMFeatures sono instabili e,
  su alfabeti non latini, `Str::slug()` restituisce stringa vuota. I record creati a mano da Nova
  prendono come sorgente il nome della piattaforma (`Str::slug(config('app.name'))` → `forestas`).
- **I test della feature stanno nella suite di `wm-package`**, che usa il database `wm_package`
  (vedi `wm-package/phpunit.xml.dist`), distinto da `forestas` e da `forestas_testing`. Va creato
  una volta con PostGIS abilitato.
- **`TaxonomyWhereLayersImportService` collega i sentieri con `syncTaxonomyWhere()`**, chiamato su
  tutti i sentieri: un sentiero senza intersezioni conserva il `taxonomy_where` che aveva. Il
  package ha rinominato `syncTracksTaxonomyWhere()` senza alias (wm-package oc:8487), e dal bump del
  24/09 la Action `ImportTaxonomyWhereFromLayersAction` creava le where e poi falliva: il test
  `tests/Feature/Import/TaxonomyWhereLayersImportServiceTest.php` copre la chiamata (oc:8641)

## Come ci siamo arrivati

- **«Il `phpunit.xml` di forestas non ha isolamento DB: lanciare la suite girerebbe sul DB
  reale»** (oc:8469) — superata da oc:8333: `phpunit.xml` imposta oggi
  `<env name="DB_DATABASE" value="forestas_testing"/>`, non commentato. Le condizioni che restano
  da verificare prima di lanciare la suite sono in
  [.claude/rules/test.md](../../.claude/rules/test.md).
