> Ticket: oc:8825

# Notes — Filtro layer con le tracce associate

## Deviazioni dal piano

Nessuna deviazione rilevante.

## Bug trovati

Nessuno oltre a quello del ticket.

## Decisioni

- **Regola a quattro passi invece di «associate, altrimenti `onLayer()`»** (challenge del 26/10,
  prima del piano): la prima regola spostava anche Sentieri e Itinerari sulla tabella
  `layerables`, che per i layer automatici è una copia aggiornata da
  `SyncAutoLayerAfterTrackTaxonomyChangeJob` con almeno 300 secondi di attesa
  (`EcTrackObserver.php:146-149`) sulla coda `default`. In locale le righe di `layerables` dei
  layer 11 e 10 sono state scritte 18 minuti e 1h37 dopo le tracce (09/04).
- **Rischio «salvare il custom field in automatico cancella le associazioni» declassato a
  ipotetico**: `assignTracksByTaxonomy()` svuota davvero un layer senza filtro (verificato con un
  test non salvato, 2 → 0 associazioni), ma l'interfaccia permette di modificare le tracce solo in
  modalità manuale e chiede conferma prima di tornare in automatico (`ConfirmModal.vue:19-21`).
- **Layer inesistente → query invariata**: con il filtro del package `onLayer(null)` andrebbe in
  errore di tipo.
- **Review dell'overview**: approvata da Alessandro Peci il 26/10 (PR #32), senza modifiche.

## Verifiche (26/10)

- 15 test in `EcTrackFiltersTest.php`, 6 nuovi. Con il filtro di develop falliscono i 3 della
  correzione (layer senza attività con associate, layer manuale, associate + area); passano quello
  del comportamento invariato (layer vuoto) e i 2 che proteggono la scelta della regola a quattro
  passi (layer automatico con attività o con `properties.taxonomy_where` che resta su `onLayer()`
  anche con un'associata diversa): questi ultimi fallirebbero con la regola scartata.
- Suite completa: 251 test passati. PHPStan: nessun errore sui file toccati (restano i 5
  preesistenti in `tests/Feature/Sus/`).
- Dati locali: Sentieri 614, Itinerari 165, Cammini di Sardegna 50, Sentieri accessibili 2;
  «Cammini di Sardegna» + Area B = 1 (prima 130).
- Verifica manuale in Nova (localhost:8000, 26/10), dalla lista EC Tracce con i filtri impostati:
  stessi conteggi. L'unica traccia di «Cammini di Sardegna» + Area B è «Sa Sinipida - Su Suercone
  (B 440)», uno dei sentieri associati al layer dei cammini: il filtro mostra il dato così com'è.

## Follow-up

- Filtro dei layer nella lista dei POI, stesso difetto (non aperto).
- Associazioni manuali e reset notturno: `layerables` non ha chiave esterna verso `ec_tracks` e il
  `TRUNCATE ... RESTART IDENTITY` la lascia su id che indicano altre tracce (non aperto).
- Correzione dei dati: fra le tracce associate a «Cammini di Sardegna» ci sono dei sentieri.
- Dopo il deploy, in dev e su UAT, verificare che i quattro layer tipologia abbiano tracce
  associate o un'attività.
