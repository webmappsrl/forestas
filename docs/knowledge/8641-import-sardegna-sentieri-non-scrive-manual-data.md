# Valori tecnici dei sentieri su Forestas

Lunghezza, dislivello, tempi di percorrenza e quote dei sentieri importati da Sardegna Sentieri: da
dove arrivano su Forestas. Le sorgenti, la precedenza e le unità valgono per tutti gli shard e sono
nel package: `wm-package/docs/knowledge/dati-dem-e-valori-manuali.md`.

## Come funziona oggi

- **L'import da Sardegna Sentieri non scrive `manual_data`.** `TrackPropertiesData::fromApiResponse()`
  passa `manual_data: null`, la chiave non esce dal DTO e l'`array_merge` di
  `SardegnaSentieriImportService::importTrackFromResponse()` lascia com'è il `manual_data` già
  presente: l'import da Sardegna Sentieri non lo scrive: lo scrivono gli operatori dal tab DEM di
  Nova e, per lunghezza e tempi, il registro catastale fino al go-live
  ([registro-catastale.md](registro-catastale.md), oc:8540). I valori `lunghezza`,
  `dislivello_totale`, `durata`, `ele_min`, `ele_max` dell'API restano in `ApiTrackResponse` ma
  nessuno li legge (oc:8641).
- **L'import non calcola nemmeno il DEM**: salva con `saveQuietly()` e non accoda la catena di
  `EcTrackService`, quindi un sentiero appena importato non ha né `manual_data` né `dem_data`. È
  voluto: con il reset notturno ricalcolare il DEM di tutti i sentieri ogni giorno caricherebbe il
  server per niente; lato client i valori arrivano con la chiamata API del dettaglio del sentiero
  (oc:8641). Il DEM si calcola la prima volta che qualcuno apre il dettaglio del sentiero in Nova,
  perché il package lo ricalcola quando manca (oc:8660).
- **Sui DB con il reset spento i valori di Drupal già importati restano** finché qualcuno non li
  cancella: il codice nuovo smette di scriverli, non li toglie. Su sviluppo e UAT li porta via il
  reset delle 06:00; la produzione partirà con il codice nuovo (oc:8641).

## Perché così

- **I valori di Drupal non sono misure** (oc:8641): nell'ultima versione del sito erano stati
  popolati con il vecchio calcolo di Webmapp, a volte sbagliato. Call tecnica Sardegna Sentieri del
  14/09/2026: «ignoriamo i valori presi da Drupal». Sui sentieri restano il calcolo automatico e il
  valore del registro storico (Excel), portato da oc:8540 ([registro-catastale.md](registro-catastale.md)).
- **Nessun comando di pulizia** (oc:8641): il sistema non è in produzione e il reset notturno
  ricrea i sentieri da zero.

## Come ci siamo arrivati

- **L'import scriveva i 6 campi in `manual_data` con `ManualTrackData::merge()`** (superata in
  oc:8641): il docblock parlava di preservare le modifiche degli operatori, ma il merge dava la
  precedenza al valore dell'API, quindi ogni import sovrascriveva le loro correzioni. E siccome il
  manuale vince sul DEM, il calcolo automatico non si vedeva su nessun sentiero.
