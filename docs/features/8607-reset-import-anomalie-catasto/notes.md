> Ticket: oc:8607

# Notes — Rigenerazione delle anomalie legata alla fine dell'import

## Divergenze dal piano, task per task

### Task 1: tabella job_batches

Il task non ha prodotto nulla: la tabella esiste già. `php artisan make:queue-batches-table`
risponde `Migration already exists`, e `Schema::hasTable('job_batches')` è `true`. Il piano
l'aveva dato per assente sulla base di una ricerca in `database/migrations/` del solo repo
principale, che non vede le migration pubblicate dal package.

Nessuna migration è stata creata.

## Bug trovati

### Le tassonomie venivano richieste alla sorgente da ogni job

`SardegnaSentieriImportService::getTaxonomyTerms()` aveva una cache, ma su una proprietà
d'istanza: il service viene risolto dentro ogni job, quindi ogni job partiva con la cache vuota e
richiedeva di nuovo gli stessi tre o quattro vocabolari. Con 1736 job significa migliaia di
chiamate ravvicinate agli stessi endpoint.

È questa la causa dei timeout, non la lentezza della sorgente: nell'analisi dei 27 job falliti,
`/ss/tassonomia/tipologia_poi` compariva 16 volte, più di qualunque singolo POI o tracciato, e
gli stessi URL aperti dal browser rispondevano senza problemi. Risolto con `Cache::remember()`
condiviso, validità un'ora.

### I ritentativi dei job erano inutili

Entrambi i job avevano `$tries = 3` ma nessun `backoff`: i tre tentativi cadevano nella stessa
finestra di pochi secondi, quindi contro una sorgente momentaneamente satura valevano quanto un
tentativo solo. Ora `$tries = 5` con `backoff()` a 30/120/300/600 secondi.

## Decisioni

- **Tag associati al ticket:** `forestas` (id 676), in fase `environment-setup: tag-ambiente`.
  `catasto-sentieri` proposto e scartato dal dev: su questo progetto il tag è `forestas`.
- **Aggancio alla fine dei job via `Bus::batch()`** invece di un innesco a coda vuota
  (scelta del dev): lega il ricalcolo all'evento vero — l'ultimo job che finisce — invece
  di dedurlo dal fatto che la coda si sia svuotata, e sa distinguere un batch riuscito da uno
  con job falliti.
- **Soglia di tolleranza sui job falliti (2%).** Zero tolleranza avrebbe reso il ricalcolo quasi
  impossibile: con 1736 chiamate HTTP a ogni reset qualche timeout è la norma, e rinunciare per
  quello avrebbe lasciato la lista vuota — cioè il difetto che questo ticket corregge. Sopra la
  soglia il ricalcolo non parte, perché le anomalie sono relazioni fra tracciati e su un archivio
  gravemente incompleto non ne escono di meno, ne escono di sbagliate. La decisione sta in
  `ImportSardegnaSentieriCommand::shouldNormalize()`, isolata lì per poterla testare.
- **L'exit code non è più il canale del fallimento.** Col batch il comando ritorna prima che i
  job finiscano: un normalize non eseguito si registra nel canale di log `import`, non nel
  codice di uscita. Il requisito dell'overview chiedeva visibilità, non uno specifico canale.
- **Su UAT si è solo guardato** (log, conteggi, configurazione, codice deployato): nessuna
  esecuzione, nessuna scrittura. La riproduzione avviene in locale.

## Prova end-to-end in locale

Eseguita il 21/09/2026 sul database locale, con la sorgente vera.

| Momento | Fatto |
|---|---|
| 08:40:32 | `sardegnasentieri:import --reset`: tronca, prepara 1736 job, dispatcha il batch e ritorna |
| — | subito dopo il ritorno del comando: `ec_tracks = 0`, anomalie `0`. È la prova diretta del difetto: il vecchio `->then()` dello scheduler partiva in questo istante |
| 08:45:46 | batch chiuso, 1736 job, **zero falliti** (prima della cache delle tassonomie ne fallivano 26) |
| 08:48:35 | `[sardegnasentieri:HJUTBSBN] Anomalie ricalcolate (job falliti: 0/1736)` |
| esito | 767 tracciati, 969 POI, **35 anomalie**: 20 geometria duplicata, 8 codice già assegnato, 7 settore discordante |

**Attenzione a come si verifica.** Fra la chiusura del batch e la scrittura delle anomalie
passano circa tre minuti: il normalize legge tutte le geometrie. Controllare la tabella subito
dopo la chiusura del batch la trova vuota e fa concludere, a torto, che il meccanismo non
funzioni — è successo durante questo lavoro.

**Due trappole dell'ambiente locale**, incontrate qui:

- **Ogni modifica ai job richiede il riavvio di Horizon**, altrimenti i worker girano con le
  classi vecchie in memoria e il trait `Batchable` appena aggiunto non ha effetto.
- **Il backup che `--reset` esegue prima di troncare fallisce**, perché il disco `wmdumps` punta
  a un bucket S3 che la macchina locale non raggiunge, e la guardia annulla tutto. Per provare
  in locale si punta il disco al minio del container (`AWS_DUMPS_ENDPOINT`,
  `AWS_DUMPS_USE_PATH_STYLE_ENDPOINT` nel `.env`, che non è tracciato) e si crea il bucket
  `wmdumps`.

### Bypass del gate PHPStan

PHPStan segnala un errore su `routes/console.php:34` (`env()` fuori dalla cartella `config`), file
compreso in questo diff. Verificato con uno `stash` che l'errore è **identico sul branch senza le
modifiche**: è debito preesistente e la riga non è toccata da questo lavoro. Bypass autorizzato dal
dev il 21/09/2026, che se ne assume la responsabilità.

## Follow-up

- **L'output del job schedulato va in `/dev/null`** (`routes/console.php`, riga generata dallo
  scheduler): è ciò che ha reso impossibile stabilire dai log se il normalize partisse. Vale un
  intervento di configurazione a sé.
- **L'import incrementale orario cancella i tracciati spariti alla fonte** (`tracksRemoved`) e
  con essi anomalie e codici, senza mai ricalcolarli. Questo ticket chiude il buco sul `--reset`;
  sul ramo orario resta aperto.
- **`geohub-import-supervisor` gira a vuoto su forestas.** Il package lo definisce in
  `wm-package/config/wm-geohub-import.php` per gli ambienti `local` e `production`, e tiene
  accesi fino a 10 worker in ascolto sulla coda `geohub-import`, che qui non riceve mai nulla:
  forestas importa da Sardegna Sentieri, non da GeoHub. Riguarda chiunque monti il package senza
  usare quel flusso, quindi va segnalato nel package e non qui.
