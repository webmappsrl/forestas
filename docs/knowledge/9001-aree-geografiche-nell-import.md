# Aree geografiche di Geohub nell'import

Come le 39 aree geografiche di Geohub (app 32) arrivano su forestas come Taxonomy Where, un Layer
ciascuna e l'overlay `aree_geografiche`, e come si torna indietro. (oc:9001)

## Come funziona oggi

### Il passo nell'import

`sardegnasentieri:import` chiama `GeographicAreasImportService::run()` **a ogni esecuzione**, con
o senza `--reset`, dopo `configure-layers` (che gira solo con `--reset`) e prima delle tassonomie.
Un errore, per esempio Geohub che non risponde, finisce nel canale `import` come «Aree geografiche
non importate, l'import prosegue.» e il giro va avanti: tracce e POI non dipendono dalle aree.

Il passo:

1. **Aree.** `TaxonomyWhereLayersImportService::import()` con la fonte
   `geohub_conf_32_geographic_areas` scarica `AreeGeograficheConLayers.geojson` da
   `geohub.webmapp.it` e il `32.json` di Geohub da wmfe, poi crea o aggiorna una Taxonomy Where per
   area, riconosciuta da `properties.source` e `properties.external_layer_id` (l'id del layer su
   Geohub). Ogni area sta in una transazione sua: un'area rifiutata (per esempio da PostGIS) non
   resta a metà, e finisce nel log come «Area non importata» con `layer_id`, nome ed errore. In
   fondo, «Import delle aree completato» con i contatori. Poi `syncTaxonomyWhere()` su tutte le
   tracce.
2. **Layer.** Per ogni area senza layer, `LayerService::createLayerFromTaxonomyWhere()` del package
   con gli eventi del pivot spenti: il layer nasce vuoto, con il nome in tutte le traduzioni, la
   descrizione e il sottotitolo dell'area. Poi `setPoiMode('manual')`. Un layer già esistente non
   si ricrea; se ha `poi_mode` vuoto (un giro interrotto a metà) torna in manuale, mentre una
   modalità scelta in Nova resta.
3. **Overlay.** La FeatureCollection `aree_geografiche` dell'app 1 si collega ai layer e passa a
   `generated`, salvata senza eventi; se è cambiata, `GenerateFeatureCollectionJob` rigenera il
   geojson con i `layer_id` dello shard e il `config.json`. Se nessuna area ha un layer, l'overlay
   non si tocca, perché la generazione lo sovrascriverebbe con un file vuoto.

**I layer li riempie `RecalculateSardegnaSentieriLayersJob`**, con la regola di tutti i layer
automatici (la traccia tocca l'area, `ST_Intersects`): nel giro normale nella catena di fine
batch; con `--only=aree`, o in un giro senza job da importare in cui sono nati layer, il comando
accoda da sé `SyncTaxonomyWhereJob` → `RecalculateSardegnaSentieriLayersJob` →
`RegenerateTaxonomyWhereOutputsJob`. Senza il reindex finale l'API di ricerca filtra ancora sul
vecchio `layers` dell'indice, e il click su un'area apre un layer senza percorsi.

### `--only=aree`

Porta solo le aree, senza la copia Drupal: gira prima del lock, dei prerequisiti e del controllo
della copia, che in locale è spenta di default. Esce con errore se il passo fallisce, e non si
combina con `--reset`, che troncherebbe tracce e POI senza reimportarli.

### Cosa si vede nell'app

- **Solo percorsi, niente POI**, come su Geohub: con i POI in automatico le aree con soli POI
  farebbero rigenerare i PBF dell'intera app a ogni ricalcolo.
- **I layer non sono nella home**, come su Geohub: il passo non tocca `config_home`, che su dev e
  UAT elenca i layer per id. Stanno in `MAP.layers` del `config.json`, perché il click sull'area li
  apra. Se `config_home` è vuota, `AppConfigService` costruisce la home con tutti i layer dell'app,
  compresi questi 39: un ambiente nuovo va configurato come gli altri.
- **Colori dell'overlay con la trasparenza** (`#a61c0066`): li legge
  `sanitizeHexColorWithAlpha()` del package; prima di oc:9001 un colore a 8 cifre usciva nero.

### Da sapere

- **Una FeatureCollection creata dal passo non entra da sola negli overlay dell'App.** Su dev e UAT
  `aree_geografiche` esiste già ed è in `config_overlays` (in `upload` fino al primo giro, verificato
  il 22/11); su un ambiente nuovo il passo la crea (con i colori e l'etichetta di Geohub) e va
  aggiunta a mano agli overlay dell'App in Nova.
- **Senza `SARDEGNASENTIERI_DAILY_RESET` l'import è orario** (`routes/console.php`), come in
  produzione e sulle macchine di sviluppo: ogni ora il passo scarica i due file da Geohub, riscrive
  le 39 aree e rifà `syncTaxonomyWhere()` su tutte le tracce, anche quando su Geohub non è cambiato
  niente. È voluto per ora: saltare le scritture quando Geohub non cambia è un follow-up annotato
  in `docs/features/9001-aree-geografiche-nell-import/notes.md`, insieme alla cancellazione delle
  aree sparite da Geohub, che oggi restano.
- **Le aree catastali non passano di qui**: restano create dalle action Nova (`Import TaxonomyWhere
  (Cadastral areas)` e «Crea Layer»), con la fonte `geohub_conf_32` e i POI in automatico.

## Come si torna indietro

Non c'è un comando apposta:

1. In Nova rimettere la FeatureCollection `aree_geografiche` in `upload` e ricaricare il file da
   `external_url`: la generazione ha sovrascritto il file caricato, perché
   `StorageService::storeFeatureCollection()` usa lo stesso percorso.
2. Cancellare i layer collegati alle Taxonomy Where con `source = geohub_conf_32_geographic_areas`,
   poi quelle Taxonomy Where.
3. Lanciare un import normale, che ricalcola luoghi e layer di tutte le tracce.

Finché il passo resta nel codice, il giro successivo ricrea aree, layer e overlay generato: per
toglierli davvero va tolto anche il passo.

## Come si verifica

In locale `docker exec php-forestas php artisan sardegnasentieri:import --only=aree`, con Horizon
acceso per la catena. Poi:

- 39 Taxonomy Where con `source = geohub_conf_32_geographic_areas` e 39 layer con
  `poi_mode = manual`;
- la FeatureCollection `aree_geografiche` in `generated` con 39 layer, e nel suo geojson ogni area
  con il suo `layer_id`;
- in `api/app/webmapp/1/config.json` l'overlay «Aree geografiche» con `rgba(166, 28, 0, 0.4)`;
- `api/v2/elasticsearch?app=geohub_app_1&layer=<id>` restituisce i percorsi del layer.

Al secondo giro i numeri restano gli stessi e nel log i layer creati sono 0.

## Perché così

- **Nell'import, con lo stesso percorso delle catastali**: allo scrum del 17/11 Giuseppe ha chiesto
  di fare le aree geografiche nella fase di import allo stesso modo delle catastali, senza creare
  niente a mano e senza prendere le zone da Drupal. Le catastali invece si creano ancora a mano in
  Nova con le due action; portarle nell'import, con un solo passo per tutte e due, è un approccio
  rinviato e annotato nelle note del ticket.
- **Layer vuoti, eventi spenti**: con gli eventi accesi, a tracce già presenti, il collegamento
  all'area fa partire un job di geometria e una catena PBF per ogni riga del pivot, centinaia di
  job per giro.
- **Overlay `generated`**: il file caricato da Geohub porta i `layer_id` di Geohub, che sullo shard
  non esistono; generato, porta quelli dello shard.
- **Due filtri diversi sul sito**: aree catastali e aree geografiche sono filtri separati su
  wp-forestas. In uscita i luoghi stanno tutti in `taxonomyWheres`, come su Geohub; dividerli per
  tipo è oc:9020.
