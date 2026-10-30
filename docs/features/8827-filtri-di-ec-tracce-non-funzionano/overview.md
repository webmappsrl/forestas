> Ticket: oc:8827

# Filtri di EC Tracce non funzionano — tracce assegnate ai layer sbagliati

## Cosa cambia

Le tracce importate da Sardegna Sentieri finiscono nel layer giusto, e ci restano dopo ogni import,
compreso il reset notturno:

| Layer | Attività che lo definisce | Tracce attese (UAT, 30/10) |
|---|---|---|
| Sentieri | `sardegnasentieri:type:sentiero` | 614 |
| Itinerari | `sardegnasentieri:type:itinerario` | 151 (cammini compresi) |
| Cammini di Sardegna | `sardegnasentieri:cammino` (nuova) | 53 |
| Sentieri accessibili | `accessible-trails` | 2 |

Quattro interventi, tutti in forestas:

1. **L'import legge il termine «Cammini di Sardegna»** (tid 4728 del vocabolario Drupal
   `tipologia_itinerari`, campo `field_tipologia_itinerario`) e lo scrive come attività
   `sardegnasentieri:cammino` sugli itinerari che lo hanno. Gli altri 8 termini del vocabolario non
   si importano.
2. **Il reset non tronca più `taxonomy_activities`**: cancella solo i legami di tracce e POI con le
   attività. Le attività restano con il loro id, l'import le ritrova per `identifier`, e i legami
   layer→attività sopravvivono alla notte. Il reset cancella anche le righe di `layerables` di
   tracce e POI, che dopo `RESTART IDENTITY` punterebbero a record diversi.
3. **A fine batch dell'import un job ricalcola i layer automatici** con
   `LayerService::assignTracksByTaxonomy()` del package, uno per layer: tipologie e aree catastali.
   Entra nella catena che già parte a fine batch, fra `SyncTaxonomyWhereJob` e
   `RegenerateTaxonomyWhereOutputsJob`, così il reindex e i file su AWS escono con i layer giusti.
4. **Un comando artisan idempotente associa le attività ai 4 layer di tipologia** secondo la mappa
   sopra, su ogni ambiente.

## Perché

Il cliente segnala (28/10) che il filtro «Layer» di EC Tracce mostra le tracce sbagliate: tappe del
cammino di Santa Barbara tra i sentieri, sentieri tra itinerari e cammini, «Sentieri» e «Cammini di
Sardegna» con gli stessi risultati. Il filtro è corretto (oc:8825): mostra le tracce associate al
layer. Sono sbagliate le associazioni.

Su UAT (30/10):

- i 4 layer di tipologia sono automatici ma **senza attività**: il reset le cancella ogni notte con
  `TRUNCATE taxonomy_activities ... CASCADE`, che porta via anche i legami con i layer;
- `layerables` non viene mai toccata dal reset: Sentieri e Cammini hanno le **stesse 597 righe**,
  scritte il 9/9 su id che dopo ogni `RESTART IDENTITY` indicano altre tracce;
- l'import non legge `field_tipologia_itinerario`, quindi nessuna attività distingue un cammino.

Allo scrum del 29/10 (14:38) si è deciso: *«i layer li lasciamo […] ma l'associazione la buttiamo
giù e se la ricrea»*.

## Requisiti

- [ ] L'import scrive l'attività `sardegnasentieri:cammino` («Cammini di Sardegna») sugli itinerari
      Drupal con il termine 4728 in `field_tipologia_itinerario`, e la toglie a quelli che non lo hanno
      più
- [ ] L'attività si crea con lo stesso meccanismo delle altre (`insertOrIgnore` più rilettura per
      `identifier`), senza id numerici nel codice
- [ ] L'import scrive le attività delle tracce senza eventi sul pivot (`TaxonomyActivityable`): i
      job per traccia del package (`SyncAutoLayerAfterTrackTaxonomyChangeJob`) non partono
      dall'import, il ricalcolo lo fa solo il job di fine batch. Le modifiche in Nova continuano a
      passare dagli observer del package
- [ ] `--reset` non tronca `taxonomy_activities`: cancella le righe di `taxonomy_activityables` di
      tracce e POI, e le righe di `layerables` di tracce e POI, su entrambe le forme del morph
      (`App\Models\…` e `Wm\WmPackage\Models\…`), senza toccare i legami dei layer
- [ ] Dopo un `--reset` le attività `sardegnasentieri:*` hanno lo stesso id di prima, e i legami
      layer→attività sono intatti
- [ ] A fine batch dell'import (nel `finally`, non a fine comando) il job di ricalcolo entra nella
      catena esistente: `SyncTaxonomyWhereJob` → ricalcolo layer → `RegenerateTaxonomyWhereOutputsJob`
- [ ] Il job ricalcola solo i layer automatici dell'app di import che hanno attività o aree: un
      layer senza filtro non viene svuotato
- [ ] Tracce: `assignTracksByTaxonomy()` su ogni layer, senza eventi su `Layerable`; poi, una volta
      per layer, `properties.layers` aggiornato in modo sincrono
      (`updateLayersPropertyOnLayeredFeature()`), geometria del layer e PBF
      (`regeneratePbfsForLayer()`) — nessun job per riga
- [ ] POI: `assignPoisByTaxonomy()` solo sui layer delle aree catastali; sui 4 layer di tipologia i
      POI restano vuoti
- [ ] Il reindex e i file su AWS (`RegenerateTaxonomyWhereOutputsJob`) partono dopo il ricalcolo:
      nell'app ogni traccia compare nei layer giusti
- [ ] Il ricalcolo è idempotente: rilanciato non cambia nulla
- [ ] Comando artisan idempotente che associa ai 4 layer di tipologia le rispettive attività;
      trova i layer per nome italiano e fallisce in modo esplicito se un layer o un'attività non
      esiste o se il nome è ambiguo; rilanciato non cambia nulla
- [ ] Dopo comando + import su UAT: Sentieri 614, Itinerari 151, Cammini 53, Accessibili 2; nessuna
      tappa CMSB in Sentieri; nessun sentiero in Itinerari o Cammini
- [ ] Test di feature su: attività dei cammini scritta dall'import, reset che conserva attività e
      legami dei layer, ricalcolo dei layer, comando di configurazione

## Rischi

- **Valanga di job dopo il reset** — `Layer::ecTracks()` e `EcTrack::taxonomyActivities()` usano
  pivot con eventi: ogni riga creata accoda PBF del layer intero e job di geometria
  (`LayerableObserver.php:56-58`), ogni attività scritta dall'import accoda un job per traccia e
  layer (`TaxonomyActivityablesObserver.php:91`). Con i layer configurati sarebbero migliaia di job
  ogni notte. *Mitigazione:* import e ricalcolo scrivono i pivot senza eventi; il job di fine batch
  fa una volta per layer quello che gli observer facevano riga per riga.
- **L'app vede i layer vecchi** — `properties.layers` si salva con `saveQuietly()` (nessun reindex)
  e la versione con job lo aggiorna in differita, dopo il reindex di fine import. *Mitigazione:*
  ricalcolo nella catena prima di `RegenerateTaxonomyWhereOutputsJob`, aggiornamento sincrono.
- **Associazioni dei POI sui POI sbagliati** — su UAT 10.350 righe POI in `layerables`, quasi tutti
  i POI in ogni layer, fino a 18 orfane per layer, sopravvissute ai `RESTART IDENTITY`.
  *Mitigazione:* il reset le cancella; il ricalcolo le ricrea solo sui layer delle aree (il legame
  layer→POI non è richiesto per i layer di tipologia).
- **Layer automatici senza filtro svuotati** — `assignTracksByTaxonomy()` fa `sync([])` su un layer
  senza attività né aree (`LayerService.php:314-317`); succederebbe se il job girasse prima del
  comando di configurazione. *Mitigazione:* il job salta i layer senza filtro.
- **Ipotetici, non coperti:** layer rinominato in Nova (il comando fallisce in modo esplicito);
  termine 4728 ricreato in Drupal; attività orfane che si accumulano senza il truncate; rollback
  del codice che riporta il truncate (si torna allo stato attuale, si rilancia il comando).

## Out of scope

- Tassonomia dedicata «Tipologia percorso» al posto delle attività: ticket a parte
- Import degli altri 8 termini di `tipologia_itinerari`
- Layer esclusivi (un cammino resta anche un itinerario)
- Richieste del cliente del 28/10 sui filtri: aree catastali «formalmente» nell'area, filtri
  separati Validazione catastale / Percorribilità, colonne dell'elenco
- I due layer «Area G (Nord-EST)» (id 1 e 7) su UAT
- POI sui layer di tipologia
- `sardegnasentieri:compare-source`: serviva al passaggio dalle API alla copia del DB e non va più
  usato, quindi non si adegua al nuovo campo
- Modifiche a `wm-package`

## Moduli toccati

Tutti in **forestas** (nessuna modifica al package):

- `app/Services/Import/Source/SardegnaSentieriDatabaseSource.php` — lettura di
  `field_tipologia_itinerario`
- `app/Services/Import/SardegnaSentieriImportService.php` — attività dei cammini sulla traccia
- `app/Console/Commands/ImportSardegnaSentieriCommand.php` — `resetData()` e aggancio del job a fine
  batch
- `app/Jobs/Import/` — nuovo job di ricalcolo dei layer
- `app/Console/Commands/` — nuovo comando di configurazione dei layer di tipologia
- `tests/Feature/` — test dei punti sopra
- `docs/knowledge/8806-filtro-ec-tracce.md`, `.claude/rules/import-sardegna-sentieri.md` —
  aggiornamento a fine lavoro
