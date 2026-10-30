> Ticket: oc:8900

# Notes — Import Sardegna Sentieri dalla copia del database Drupal invece che dalle API

## Deviazioni dal piano

### Task 4: url del nodo confrontato e non escluso

L'overview e il piano escludevano dal confronto il campo `url`, dato per non usato. Era falso: lo
salvano `app/Dto/Import/ForestasTrackData.php:34` e `ForestasPoiData.php:29` nelle properties
`forestas`. La ricerca durante la challenge aveva guardato solo service e job. Il campo si ricostruisce
dalla copia (`path_alias` della lingua originale, altrimenti `/node/{id}`) e il confronto lo verifica.

### Task 2: codice del modulo Drupal non disponibile

Il piano dava per riferimento la tabella di corrispondenza della pagina di conoscenza. Il codice del
modulo `api_webmapp`, che ne avrebbe chiarito le regole, non è scaricabile senza la chiave Acquia: le
regole non documentate si sono ricavate confrontando le risposte vere delle API. La prima trovata:
nei campi di testo multipli e tradotti (`info_utili`, `roadbook`, `come_arrivare`) la seconda lingua
riporta i valori della prima seguiti dai propri (sentiero 164).

## Bug trovati

## Decisioni

- **Il codice HTTP di lettura resta, solo per il confronto** (approvato con il piano, 29/10). L'overview
  chiedeva di rimuovere la lettura dalle API senza interruttore; il comando di confronto però deve
  leggere anche dalle API. Il codice si sposta in `SardegnaSentieriApiSource`, usata solo da
  `sardegnasentieri:compare-source` e mai dall'import; va rimossa con il comando alla dismissione di
  Drupal.
- **Stima saltata** su richiesta del dev.
- **Tag Orchestrator saltati** su richiesta del dev.

## Follow-up

- Filtrare le bozze (`status = 0`) è una decisione a parte: oggi l'import le porta, come le API.
- Recupero dei file di Drupal (immagini, GPX, allegati) dopo la chiusura del sito.
- Spegnere lo scheduling dell'import alla dismissione di Drupal, insieme a `SardegnaSentieriApiSource`
  e al comando di confronto.
- Prima della dismissione di Drupal, test con fixture per `SardegnaSentieriDatabaseSource`: un
  campione di righe della copia (nodi 164, 3636, 1105, 1266) con le risposte API attese. Spente le
  API, `compare-source` non potrà più verificare le modifiche alle query (review finale, I6).
- Minor della review finale non corretti: alias `und` ignorati in `url()`; parent delle tassonomie
  senza `deleted = 0`; cambiare `SARDEGNASENTIERI_FILES_URL` riscarica tutte le immagini (va scritto
  nel commento della config); `release(600)` × 5 tentativi copre circa 50 minuti di ricarica.
