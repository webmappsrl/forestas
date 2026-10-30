> Ticket: oc:8900

# Import Sardegna Sentieri dalla copia del database Drupal invece che dalle API

## Cosa cambia

L'import da Sardegna Sentieri smette di leggere i dati dalle API HTTP di Drupal (`/ss/*`,
`/node/{id}`, `/taxonomy/term/{id}`) e li legge dalla copia SQL del database Drupal, attraverso la
connessione `sardegnasentieri` già definita in `config/database.php`. **Cambia solo la fonte**: i
campi scritti, i posti in cui vengono scritti, lo scheduling, i job, le code, la logica
incrementale, il `--reset` e il ricalcolo delle anomalie restano identici.

I file (immagini, allegati, GPX) continuano a essere scaricati via HTTP dal sito pubblico: dalla
copia si ricava il percorso (`file_managed.uri`) e l'URL si ricostruisce con un URL base
configurabile.

La copia entra in `compose.yml`, così UAT e la futura produzione la avviano con il resto dello
shard; in locale resta un container a parte, come oggi, o il tunnel verso la copia di UAT.

Per ogni nodo l'import legge dalla copia il **record completo** — tutte le traduzioni, tutti i
campi del tipo, le entità richiamate — e ne usa solo ciò che scrive oggi: il resto è pronto per
quando servirà, senza query nuove (scrum del 30/10/2026: «prendili tutti, poi al momento usi solo
quelli che erano già usati»).

Mentre la copia si ricarica l'import non la legge: lo script di ricarica tiene un lock per tutta la
durata, e comando e job lo rispettano.

## Perché

Allo scrum del 29/10 (14:38) Bonfanti ha chiesto di staccare l'import dalle API di Drupal, per tre
motivi: uniformare gli import (WordPress legge già dalla copia), non caricare Drupal di richieste,
avere accesso a tutti i dati, compresi quelli interni che le API non espongono. All'inizio del
progetto l'accesso al database non c'era, ed è per questo che l'import è nato sulle API.

Drupal va dismesso entro fine anno: l'ultima copia resterà la fonte del dato, e dopo il dato vivrà
solo sui server di Forestas. L'import dalla copia è quindi una fase transitoria.

Conseguenza accettata: finché Drupal è vivo, una modifica fatta lì arriva su Forestas dopo il dump
giornaliero (cron alle 10:00), non entro un'ora. Oggi l'unico ambiente è UAT, dove l'import gira già
una volta al giorno (`--reset` alle 06:00), quindi in pratica non cambia nulla.

Questo ticket è il primo passo, a risultato invariato. Il censimento e l'import dei dati oggi non
importati è un ticket successivo, da cui dipende oc:8881 (il dato dei cammini).

## Requisiti

- [ ] La lettura dei dati dell'import passa dalle API alla copia del DB Drupal, dietro un'unica
      interfaccia di lettura che restituisce gli stessi DTO di oggi (`ApiTrackResponse`,
      `ApiPoiResponse`, tassonomie, enti, liste `{nid: changed}`).
- [ ] Il codice che legge i dati dalle API viene rimosso, senza un interruttore `api`/`db`; il
      client HTTP resta solo per scaricare i file (GPX, immagini, allegati).
- [ ] Le liste di sentieri, itinerari e POI includono i nodi non pubblicati, come le API oggi
      (nessun filtro su `status`): sulla copia di UAT sono 3 sentieri, 1 itinerario e 23 POI in
      bozza, che le API già espongono e l'import già porta in Forestas. Filtrarli è una decisione a
      parte, fuori da questo ticket.
- [ ] `changed` si legge dalla riga della lingua originale del nodo (`default_langcode = 1`):
      `node_field_data` ha una riga per traduzione.
- [ ] Se la query di dettaglio non trova il nodo, il lettore lancia un'eccezione esplicita, come
      oggi la 404 delle API: mai un DTO vuoto, che il job scriverebbe sopra il sentiero esistente.
- [ ] `scripts/drupal-dump-reload.sh` crea un lock all'inizio e lo toglie solo a ricarica riuscita.
      Con il lock presente il comando salta l'esecuzione e i job si rimettono in coda per più tardi.
- [ ] I valori usati come confronto dall'import incrementale (`changed` → `updated_at`) e le date
      (`created`, `data_rilievo`) hanno lo stesso formato e lo stesso fuso delle API (ora
      italiana): un formato diverso farebbe reimportare tutto alla prima esecuzione.
- [ ] Gli URL dei file ricostruiti dalla copia sono identici, carattere per carattere, a quelli
      delle API (codifica dei nomi con spazi e parentesi compresa). L'URL è la chiave con cui
      `SardegnaSentieriMediaSyncService` riconosce un'immagine già scaricata: un URL diverso fa
      cancellare e riscaricare le immagini.
- [ ] L'URL base dei file è in configurazione, con il valore attuale come default, così che non
      serva una variabile nuova nel `.env` del server (`deploy_prod.sh` congela la configurazione).
- [ ] Il controllo iniziale del comando, che protegge il `--reset` dal troncamento a fonte assente
      (incidente del 10/09/2026), legge la lista dei sentieri dalla copia e la vuole non vuota, al
      posto di `isReachable` sulle API: una semplice connessione riuscirebbe anche su una copia vuota.
- [ ] Scheduling invariato: `--reset` alle 06:00 con `SARDEGNASENTIERI_DAILY_RESET` acceso,
      altrimenti ogni ora.
- [ ] Un comando in sola lettura confronta, per tutti i nodi, ciò che l'import legge dalle API con
      ciò che legge dalla copia: i DTO di sentieri e POI, le liste, e gli array grezzi di tassonomie,
      avvertenze, enti e termini. Esclude i nodi con `changed` diverso (modificati dopo il dump) e
      un elenco esplicito di campi che l'import non usa (`url` del nodo, e quelli letti ma non usati
      come `lunghezza`, `durata`, `ele_min`, `ele_max`). Si lancia in locale con il tunnel verso la
      copia di UAT; il cambio di fonte è pronto quando non ci sono differenze sui campi usati.
- [ ] I test dell'import usano una fonte simulata al posto di `Http::fake` per i dati; il download
      dei file resta simulato via HTTP.
- [ ] `sardegnasentieri-dump.compose.yml` è incluso in `compose.yml` e non in `develop.compose.yml`
      né in `local.compose.yml`; va verificato che gli `extends` dei due compose non lo trascinino.
- [ ] In locale Laravel raggiunge il tunnel con `DB_SARDEGNASENTIERI_HOST=host.docker.internal` e
      `DB_SARDEGNASENTIERI_PORT=3307`, documentato nell'howto della copia.

## Rischi

- **Import che legge mentre la copia si ricarica.** `scripts/drupal-dump-reload.sh` fa
  `DROP DATABASE` e ricarica una tabella alla volta, mentre l'utente `readonly` resta valido: per
  alcuni minuti si leggono tabelle a metà (nodi senza immagini né GPX). Il cron del dump è alle
  10:00 e l'import orario parte anche alle 10:00; `pruneRemoved` cancellerebbe le immagini già
  scaricate. Mitigato dal lock della ricarica; lo scheduling resta invariato per scelta.
- **URL o date con un formato diverso da quello delle API**: riscaricamento di tutte le immagini o
  reimport completo. Le API danno l'URL del file originale, con il percorso codificato
  (`Carta%201.%20Baronie%20e%20Montalbo%20%28unita%202%29.gpx`): la codifica va replicata
  esattamente. Mitigato dal comando di confronto, che è la condizione per considerare il lavoro
  finito.
- **La documentazione della copia filtra `status = 1`** (`docs/knowledge/copia-database-drupal.md`,
  passo 1 e query di esempio), l'opposto di quanto serve qui: chi la segue esclude le bozze. La
  pagina va corretta distinguendo l'import, che replica le API, da WordPress, che mostra solo il
  pubblicato.
- **I file restano sul sito pubblico**: alla chiusura di Drupal il download di immagini e GPX
  smette di funzionare. Fuori da questo ticket; l'URL base configurabile permette di spostarli
  senza toccare l'import.

## Out of scope

- Scrittura dei dati presenti nella copia e non esposti dalle API: il record completo li legge già,
  usarli è un ticket successivo (prerequisito di oc:8881).
- Aggiornamento degli enti già importati, che oggi non vengono riletti: oc:8513.
- Recupero dei file di Drupal (immagini, allegati, GPX) dopo la chiusura del sito.
- Messa in opera della copia sul server di produzione vero, che oggi non esiste: chiave Acquia,
  firewall e cron di root si fanno a mano sull'host, come su UAT.
- Spegnimento dello scheduling dell'import alla dismissione di Drupal.

## Moduli toccati

Tutto nel repo principale `forestas`; `wm-package` e `wp-forestas` non vengono toccati.

- `app/Http/Clients/SardegnaSentieriClient.php` — resta solo il download dei file
- nuova interfaccia di lettura e sua implementazione sulla connessione `sardegnasentieri`
  (`app/Services/Import/` o `app/Repositories/`)
- `app/Services/Import/SardegnaSentieriImportService.php` — tassonomie, enti, GPX
- `app/Jobs/Import/ImportSardegnaSentieriTrackJob.php`, `ImportSardegnaSentieriPoiJob.php`
- `app/Console/Commands/ImportSardegnaSentieriCommand.php` — liste e controllo iniziale
- `app/Dto/Api/*` — costruzione dei DTO dalla nuova fonte, se serve
- nuovo comando di confronto API contro copia (sola lettura)
- `config/database.php` o `config/forestas.php` — URL base dei file
- `compose.yml` — `include` di `sardegnasentieri-dump.compose.yml`
- `scripts/drupal-dump-reload.sh` — lock per la durata della ricarica
- `tests/Feature/Import/*`
- `docs/knowledge/copia-database-drupal.md`, `docs/howto/copia-database-drupal.md`,
  `.claude/rules/import-sardegna-sentieri.md`
