> Ticket: oc:8932

# [FORESTAS] import nello shard dei dati di Drupal che mancano al nuovo sito

## Cosa cambia

L'import da Sardegna Sentieri, che legge la copia del DB Drupal (oc:8900), porta nello shard i dati
che il nuovo sito WordPress deve mostrare e che oggi non arrivano, o arrivano in una forma che
WordPress non può usare. Si parte dalle tassonomie, poi relazioni, media, link e valori altimetrici.

Il lavoro parte da `develop`, che contiene già il modello unico delle classificazioni di oc:8910
(`TaxonomyTerm` del `wm-package`, commit del package `9618dc6`).

## Perché

Il nuovo sito replica sardegnasentieri.it (wireframe di Sardegna Sentieri) leggendo i dati dallo
shard. Allo scrum del 04/11 Giuseppe ha chiesto un ticket operativo con «cosa manca» a WordPress;
allo scrum del 06/11 (08:31) ha fissato la priorità: «concentrati prima sulle tassonomie che ci
servono anche per il frontend». Quando l'import leggeva le API di Drupal alcuni campi non erano
esposti; ora che legge la copia del DB sono tutti raggiungibili.

## Requisiti

### Tassonomie (prima parte del lavoro)

- [ ] **Tipi di POI e attività con i nomi di Drupal.** Ogni termine dei vocabolari `tipologia_poi`,
  `servizi`, `tipologia_sentieri` e `tipologia_itinerari` diventa un record a sé di
  `TaxonomyPoiType` / `TaxonomyActivity`, con identifier `sardegnasentieri:tid:<tid>` (lo schema di
  `DRUPAL_TERM_PREFIX` di oc:8910) e `name` it/en da `taxonomy_term_field_data` (inglese se la
  traduzione esiste, altrimenti italiano). L'identificativo Geohub (`field_geohub_identifier`) non
  si usa più come identifier.
- [ ] **Dipendenze dagli identifier Geohub adeguate.** Nello stesso lavoro passano ai tid di Drupal:
  `depends_on` delle difficoltà in `ConfigureTaxonomyTermsCommand` (oggi `hiking`, `mtb`, `horse`),
  la mappa delle icone dei tipi di POI in `SardegnaSentieriImportService` (oggi per identifier, per
  esempio `crossroads` → `txn-guidepost`) e i layer configurati per attività (oc:8827).
- [ ] **Zone geografiche come `TaxonomyWhere`.** I 34 termini del vocabolario `zona_geografica`
  diventano `TaxonomyWhere` con `name` it/en e `properties.source` che le identifica come zone di
  Sardegna Sentieri. Descrizione e immagine della zona vanno in `properties` (`TaxonomyWhere` non ha
  media). Nessun poligono: su Drupal il campo è vuoto.
- [ ] **Assegnazione delle zone da Drupal nella pivot `taxonomy_whereables`.** Sentieri, itinerari
  e POI ricevono le zone scelte dalla redazione (`field_zona_geografica`, anche più d'una), scritte
  nella pivot e non in `properties.taxonomy_where`, che `SyncTaxonomyWhereJob` sovrascrive a fine
  import con il calcolo geometrico.
- [ ] **Zone visibili in Nova.** Un `Field` nella Resource `EcTrack` e `EcPoi` di forestas mostra le
  zone lette dalla pivot, al posto del `KeyValue` «Zona geografica» con i tid grezzi.

### Relazioni fra contenuti

- [ ] **POI degli itinerari.** `field_punti_di_interesse` dell'itinerario entra in `ec_poi_ec_track`
  come oggi `field_poi_correlati` del sentiero (oggi gli itinerari hanno solo partenza e arrivo).
- [ ] **Tracce correlate a una traccia**, relazione nuova `EcTrack` → `EcTrack` con migrazione in
  forestas: `field_sentieri_collegati` e `field_itinerari_correlati` di sentieri e itinerari, con la
  direzione di Drupal (su 2.083 coppie solo 995 hanno la reciproca) e senza i doppioni che Drupal
  salva.
- [ ] **Tracce correlate a un POI**, relazione nuova `EcPoi` → `EcTrack` con migrazione in forestas:
  `field_sentieri_collegati` e `field_itinerari_correlati` del POI, separata da `ec_poi_ec_track`
  (su Drupal le due liste coincidono solo per 200 coppie su 581 lato POI e 1.699 lato traccia).
- [ ] **Risoluzione dopo che tutti i nodi sono importati.** Le relazioni fra contenuti si risolvono
  per nid Drupal quando tutti i record esistono, così un contenuto importato dopo chi lo cita non
  resta scollegato fino al giro successivo.
- [ ] **POI correlati a un POI**: già in `ec_poi_related_pois` (635 righe in locale contro le 637 del
  ticket); si verifica solo perché ne mancano 2.
- [ ] Le nuove relazioni sono visibili come `Field` nelle Resource Nova di forestas e vengono
  svuotate dal reset notturno (non hanno chiavi esterne raggiunte dal `TRUNCATE ... CASCADE`).

### Media, link e altri campi

- [ ] **Ente:** immagine principale da `field_immagine_principale_media` e galleria da
  `field_galleria_media` nella media collection dell'ente; `field_collegamenti` (10 enti).
- [ ] **Allegati dei POI** da `field_allegati_media` (12 POI): oggi l'import li scrive sempre vuoti.
- [ ] **Media della traduzione inglese come ripiego**: per immagine principale, galleria e allegati,
  se il campo è vuoto nella lingua originale del nodo si prendono i valori della traduzione inglese
  (3 sentieri con allegati, 20 immagini). Mai unione delle due liste.
- [ ] **`field_collegamenti` → `properties.related_url`** per itinerari (75) e POI (47), nel formato
  del `KeyValue` «Related URL» del package; nella Resource `EcTrack` di forestas si aggiunge il
  campo «Related URL», che oggi c'è solo su `EcPoi`.
- [ ] **Ordine di visualizzazione** (`field_ordine_di_visualizzazione`, 2 sentieri e 31 itinerari)
  importato sulla traccia.

### Valori altimetrici

- [ ] **Quote, dislivelli, lunghezza, tempo e grafico altimetrico dal DEM**, non da Drupal: resta
  valida la decisione di oc:8641 («ignoriamo i valori presi da Drupal»). Il DEM e il grafico
  (`UpdateEcTrackGenerateElevationChartImage`) partono dopo l'import per le tracce con geometria
  nuova o cambiata. Lunghezza e tempo dei sentieri dall'Excel del registro restano dove ci sono.
  Finché c'è il reset notturno (temporaneo) su UAT il calcolo gira su tutte le tracce, e si accetta.

### Verifica

- [ ] Test automatici sulla lettura dei campi nuovi dalla copia Drupal e sulla loro scrittura.
- [ ] Comando Artisan in sola lettura che confronta, dato per dato, i conteggi su Drupal e nello
  shard dopo un import (sul modello di `sardegnasentieri:compare-source`).
- [ ] Controllo in Nova dei casi del ticket: sentiero B 401 (ec-track 26) con 5 sentieri e 2
  itinerari correlati, itinerario Lago di Baratz (ec-track 465) con gli 8 POI, ente GAL BMG con
  immagine e galleria.
- [ ] `sardegnasentieri:compare-source` continua a uscire con 0: i campi che esistono solo nella
  copia del DB non sono una differenza.
- [ ] Label dei `Field` Nova nuovi in `lang/it.json` e `lang/en.json`.

## Domande aperte

- **Zone geografiche calcolate dal poligono.** Per ora l'assegnazione viene da Drupal. Restano da
  decidere da dove prendere i poligoni (Geohub ne ha 39 contro le 34 di Drupal, e solo una ventina
  hanno un nome simile), cosa fare delle zone senza corrispondenza, e come convivono calcolo e
  assegnazione della redazione.
- **Abbandono degli identifier Geohub.** Va confermato con il team che l'app e gli altri consumatori
  dello shard non contano su identifier come `hiking` o `crossroads`.
- **oc:8920 deve conservare le zone di Drupal.** Quando `syncTaxonomyWhere()` scriverà
  `taxonomy_whereables`, deve sostituire solo le righe che calcola lui e conservare quelle con
  un'altra `source`, altrimenti cancella le zone importate qui. Va aggiunto come vincolo a oc:8920.

## Rischi

[Da compilare dopo la Fase: challenge]

## Out of scope

- API che espongono questi dati a WordPress: ticket a parte.
- Visibilità delle zone in Elastic, GeoJSON e filtri dei layer: arriva con oc:8920.
- Dati nuovi chiesti dal wireframe e assenti su Drupal: percorribilità, avviso, inclusività,
  recensioni su itinerari e tappe, cammino come contenuto a sé.
- I cinque «Punti aperti» del ticket (cammino in sintesi, data di accatastamento, «Carte»,
  servizi territoriali, rifugi gestiti): aspettano decisioni del cliente.
- Segnalazioni e valutazioni (Webform): per la decisione del 29/10 vanno prima su WordPress.
- Area riservata (`field_files_privati`): da chiedere a Forestas.
- Campi non mostrati sul sito Drupal (tappe dell'itinerario, tag, `field_data`, servizi degli enti,
  iframe mappa, contiene carte), salvo l'ordine di visualizzazione.
- Immagini sopra i 20 MB (limite `media-library.max_file_size`).
- Cache dei risultati del DEM per sopravvivere al reset notturno, che è temporaneo.

## Moduli toccati

Tutto nel repo forestas; il `wm-package` non viene modificato.

| File | Cosa |
|---|---|
| `app/Services/Import/Source/SardegnaSentieriDatabaseSource.php`, `DrupalRecordReader.php`, `DrupalValues.php` | lettura dei campi nuovi, nomi dei termini, ripiego sull'inglese per i media |
| `app/Services/Import/SardegnaSentieriImportService.php` | scrittura di tassonomie, zone, relazioni, media, `related_url`, ordine; mappa delle icone |
| `app/Dto/Api/*`, `app/Dto/Import/ForestasTrackData.php`, `ForestasPoiData.php` | campi nuovi dei DTO |
| `app/Console/Commands/ImportSardegnaSentieriCommand.php` | passaggio finale per le relazioni, avvio del DEM, svuotamento delle pivot nuove nel reset |
| `app/Console/Commands/ConfigureTaxonomyTermsCommand.php`, `ConfigureSardegnaSentieriLayersCommand.php` | `depends_on` e layer sui tid di Drupal |
| `app/Console/Commands/CompareSardegnaSentieriSourceCommand.php` | campi presenti solo nella copia del DB |
| `app/Console/Commands/` (nuovo) | confronto dei conteggi Drupal ↔ shard |
| `app/Models/EcTrack.php`, `EcPoi.php`, `Ente.php` | relazioni nuove |
| `database/migrations/` (nuove) | pivot traccia → traccia e POI → traccia |
| `app/Nova/EcTrack.php`, `EcPoi.php`, `Ente.php` | `Field` per zone, relazioni, «Related URL» |
| `lang/it.json`, `lang/en.json` | label nuove |
| `tests/Feature/Import/` | test dei campi nuovi |
