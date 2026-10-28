> Ticket: oc:8880

# Import Sardegna Sentieri: le aree catastali (taxonomy_where) non vengono ricalcolate sulle tracce

## Cosa cambia

A fine import Sardegna Sentieri, nella callback `finally` del batch, l'import accoda
`SyncTaxonomyWhereJob` del wm-package: la stessa cosa che fa l'action Nova
«Sincronizza Taxonomy Where su EC Features» (`SyncEcTaxonomyWhereAction`). Il job scrive in
`properties.taxonomy_where` di tutte le EC Track e gli EC POI le aree che ne intersecano la
geometria, con un unico `UPDATE`, in pochi secondi.

Dopo il sync, in catena, parte `RegenerateTaxonomyWhereOutputsJob` per l'app 1
(`SardegnaSentieriImportService::IMPORT_APP_ID`): reindicizza le tracce su Elasticsearch, accoda
l'aggiornamento dei JSON su AWS e rigenera il geojson dei POI. È la stessa sequenza del comando
del package `wm:resync-taxonomy-where`.

La catena parte **sempre**, come prima istruzione del `finally`: con il reset, con l'import
incrementale, con `--only` e anche quando il reset supera la soglia dei job falliti. Va sulla coda
`sardegnasentieri-import`, non su `default`.

## Perché

L'import salva le tracce con `saveQuietly()`, quindi nessun observer calcola `taxonomy_where`, e il
sync oggi parte solo a mano dall'action. Con il reset giornaliero (dev e UAT) le tracce rinascono
ogni mattina senza aree e il filtro «Aree catastali» della lista EC Tracce non restituisce nulla:
su UAT, il 27/10, 0 tracce su 762 avevano `taxonomy_where`.

Decisione dello scrum del 27/10 (call delle 15:36), confermata il 28/10: «nell'import si aggiunge
lo stesso comando».

- **Sempre, prima delle uscite anticipate**: le guardie del `finally` proteggono anomalie e registro
  catastale, che su un archivio incompleto producono dati falsi. Il sync delle aree no: ricalcola
  tutto dalla geometria e si può ripetere senza danni.
- **Coda `sardegnasentieri-import`**: su `default` il job finirebbe dietro le migliaia di
  conversioni immagine accodate dall'import e girerebbe ore dopo (stessa trappola di oc:8607).
- **Reindex in catena**: `SyncTaxonomyWhereJob` scrive solo nel DB, mentre l'indice Elasticsearch
  delle tracce contiene `taxonomyWheres` letto da `properties.taxonomy_where`
  (`wm-package/src/Models/EcTrack.php:759-761`). Senza reindex le app vedrebbero le aree vuote. La
  catena garantisce che il reindex legga le aree già scritte: due dispatch separati potrebbero
  girare in parallelo.

## Requisiti

- [ ] A fine batch dell'import viene accodata, sulla coda `sardegnasentieri-import`, la catena
      `SyncTaxonomyWhereJob` → `RegenerateTaxonomyWhereOutputsJob(IMPORT_APP_ID)`
- [ ] La catena parte in tutti e quattro i casi del `finally`: reset riuscito, reset oltre la
      soglia dei job falliti, import incrementale, `--only`
- [ ] Il resto del `finally` (anomalie, registro catastale, log) non cambia comportamento
- [ ] Test sul comando: un caso per ciascuna delle quattro strade del `finally`, che verifica la
      catena (ordine dei due job e coda `sardegnasentieri-import`)
- [ ] Verifica su UAT dopo il reset del mattino: tracce con `taxonomy_where` > 0, filtro
      «Aree catastali» della lista EC Tracce con risultati, documenti Elasticsearch delle tracce con
      `taxonomyWheres` valorizzato

## Rischi

- **Elasticsearch non riallineato dal solo sync** (emerso nella challenge, verificato sul codice):
  mitigato con `RegenerateTaxonomyWhereOutputsJob` in catena.

## Out of scope

- Ricalcolo dei layer legati alle aree (`layerables`, `properties.layers`, PBF) dopo il reset: è
  nel ticket separato sui layer, deciso allo scrum del 27/10.
- Modifiche a `SyncTaxonomyWhereJob`, `RegenerateTaxonomyWhereOutputsJob` o
  `GeometryComputationService` nel wm-package.

## Moduli toccati

Tutti nel repo forestas:

- `app/Console/Commands/ImportSardegnaSentieriCommand.php` — `dispatchImportBatch()`, callback
  `finally`
- `tests/Feature/Import/` — test sui quattro casi del `finally`
- `docs/knowledge/import-asincrono-e-anomalie.md` — cosa parte a fine batch
