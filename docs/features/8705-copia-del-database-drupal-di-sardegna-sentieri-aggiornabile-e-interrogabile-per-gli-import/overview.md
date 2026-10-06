> Ticket: oc:8705

# Copia del database Drupal di Sardegna Sentieri aggiornabile e interrogabile per gli import

## Cosa cambia

La copia del database Drupal di Sardegna Sentieri, già caricata in locale dal 22/04/2026 nel
container `mysql-sardegnasentieri-dump`, diventa uno strumento di lavoro stabile:

- il dump si deposita in una cartella che dichiara cosa contiene, `storage/drupal-dump/`, al posto
  di `storage/dump/`;
- uno script shell, lanciato a mano dall'host (Mac in locale, Ubuntu su UAT), sostituisce la
  copia con il dump più recente della cartella (`.sql` o `.sql.gz`), senza cancellare volumi
  Docker;
- l'utente `readonly`, usato dalla connessione Laravel `sardegnasentieri` e dalle query di
  esplorazione, ha davvero il solo permesso `SELECT`;
- una pagina di conoscenza descrive lo schema Drupal utile agli import (bundle, tabelle dei campi,
  tassonomie, file e media, relazioni, traduzioni), dove si trova nella copia ogni dato che
  l'import prende oggi dalle API, e come raggiungere la copia locale e quella di UAT; il
  `CLAUDE.md` la indicizza, così che in ogni sessione si sappia che la copia esiste e come
  interrogarla.

La copia è stata ricaricata a mano il 05/10/2026 con il dump di produzione di quel giorno (ultimo
`changed` in `node_field_data`: 04/10/2026), per poterne studiare lo schema; la ricarica con lo
script la rifà come prova.

## Perché

Le API pubbliche di Sardegna Sentieri (modulo custom `api_webmapp`, tag `2024-04-10-bis` in
produzione) non espongono tutti i dati: dei sentieri mancano i dislivelli positivo e negativo,
`sentieri_collegati` e `itinerari_correlati`; degli itinerari nessun campo proprio (sentieri che li
compongono, punti di interesse, tipologia); interi bundle come `news`, `da_sapere` ed `evento`
non sono esposti affatto. Drupal verrà dismesso
e Forestas ne diventa la sorgente del dato: per recuperare quei campi, e in seguito per fare gli
import direttamente dalla copia SQL al posto delle API, serve una copia aggiornabile e uno schema
leggibile su cui scrivere le query.

Il dev riceve dalla produzione un dump giornaliero
(`prod-sardegnasentieri-sardegnasentieriuyyrag83f9-AAAA-MM-GG.sql.gz`) e lo porta in locale a
mano.

## Requisiti

- [ ] Compose, connessione e script funzionano identici su macOS (Docker Desktop) e su Ubuntu
      (UAT), anche se in questo ticket il container si accende solo in locale
- [ ] Il compose del dump monta `storage/drupal-dump/` in sola lettura su un percorso neutro
      (`/drupal-dump`), **non** su `/docker-entrypoint-initdb.d`: l'unica strada di caricamento è
      lo script; `storage/drupal-dump/` resta ignorata da git
- [ ] Il compose non definisce più `MYSQL_USER`/`MYSQL_PASSWORD` (l'immagine darebbe
      `ALL PRIVILEGES` a quell'utente) e tiene `MYSQL_DATABASE`, perché il dump non contiene `USE`
- [ ] Il container gira con `--skip-log-bin`: in una copia di sola lettura il binlog occupa solo
      disco
- [ ] La porta è pubblicata solo sul loopback (`127.0.0.1:${DOCKER_DRUPAL_DUMP_PORT:-3307}:3306`):
      `docker port mysql-sardegnasentieri-dump` non riporta mai `0.0.0.0` né `[::]`. La copia non
      è raggiungibile da internet, ma resta raggiungibile da un tunnel SSH
- [ ] La connessione Laravel `sardegnasentieri` raggiunge la copia attraverso la rete Docker, con
      il nome del container (`mysql-sardegnasentieri-dump:3306`), senza `host.docker.internal`
      (che su Linux non esiste)
- [ ] Lo script `scripts/drupal-dump-reload.sh`, lanciato dall'host, scritto in `bash` portabile
      (niente opzioni che cambiano fra macOS e Linux, come `stat -f` o `sed -i ''`), con
      `set -euo pipefail`:
  - [ ] prende il dump più recente in `storage/drupal-dump/` in base alla data nel nome del file
        (`.sql` o `.sql.gz`), e si ferma con un messaggio chiaro se non ce n'è nessuno
  - [ ] verifica che il container `mysql-sardegnasentieri-dump` sia acceso prima di procedere
  - [ ] come root, cancella e ricrea il database `sardegnasentieri` e vi carica il dump con
        `docker exec … mysql`, senza toccare volumi Docker né altri container
  - [ ] ricrea l'utente `readonly` con il solo `SELECT` su `sardegnasentieri.*`
  - [ ] a fine caricamento stampa il nome del file caricato, il numero di tabelle e la data
        dell'ultimo `changed` in `node_field_data`
  - [ ] si interrompe al primo errore, senza dichiarare successo su un caricamento parziale
- [ ] Dopo ogni ricarica `SHOW GRANTS FOR readonly` riporta solo `SELECT`. Un container nuovo
      parte con il database vuoto e senza `readonly` finché non si lancia lo script
- [ ] Lo script inutilizzabile `docker/sardegnasentieri-init/01-readonly.sh` (comandi PostgreSQL,
      mai montato) viene rimosso
- [ ] La connessione Laravel `sardegnasentieri` continua a funzionare da `php-forestas`
      (`select 1`), e un tentativo di scrittura con `readonly` viene rifiutato
- [ ] Pagina di conoscenza sulla copia Drupal in `docs/knowledge/`, che si apre con **come si
      raggiunge una copia**: la copia locale (come verificare che ci sia, come accenderla e
      caricarla da zero) e la copia di UAT dal locale, con il tunnel SSH
      (`ssh -N -L 3308:127.0.0.1:3307 uat.forestas`), valido da quando il ticket di sync l'avrà
      messa lì. Poi: come si interroga (da shell, con `--default-character-set=utf8mb4`, e da
      Laravel), mappa dello schema dei bundle `sentiero`, `poi`,
      `itinerario`, `news` (tabelle `node__field_*`, tassonomie, file/media, relazioni fra nodi,
      `langcode`), elenco dei campi non esposti dalle API attuali, trappole dello schema
- [ ] Nella stessa pagina, una tabella di corrispondenza per le chiamate che l'import fa oggi
      (`SardegnaSentieriClient`: `/ss/list-tracks/`, `/ss/track/{id}`, `/ss/listpoi/`,
      `/ss/poi/{id}`, `/ss/tassonomia/{vocabolario}`, `/node/{id}`, `/taxonomy/term/{id}`): per
      ogni campo che Forestas legge davvero, la tabella e la colonna della copia da cui viene; i
      campi che l'API calcola o trasforma, con come si ricostruiscono; i campi assenti dalla copia
      (i GPX sono file su disco, se ne ricava solo l'URL da `file_managed`)
- [ ] Le query e gli esempi della pagina mostrano solo struttura e contenuti pubblici, senza conteggi:
      mai righe di `users*`, `webform_submission*` o altri dati personali
- [ ] Riga nell'indice `## Conoscenza` del `CLAUDE.md` di Forestas che rimanda alla pagina
- [ ] Trappole sulla copia aggiunte a `.claude/rules/import-sardegna-sentieri.md`
- [ ] La copia viene ricaricata con il dump del 05/10/2026 come prova finale dello script

## Rischi

- **Binlog e disco** — MySQL 8 tiene il binlog per 30 giorni (`@@log_bin = 1`), e ogni ricarica ne
  scrive quanto i dati (circa 10 GB) sul disco della VM Docker, che oggi ha 13 GB liberi. Mitigato
  con `--skip-log-bin`. Il PostgreSQL di Forestas non ne risente: i suoi dati stanno sul disco
  dell'host, non su quello della VM.
- **Due strade di caricamento** — con la cartella del dump montata su
  `/docker-entrypoint-initdb.d`, un volume ricreato caricherebbe tutti i dump presenti uno sopra
  l'altro, e `MYSQL_USER` riceverebbe `ALL PRIVILEGES`. Mitigato montando la cartella su un
  percorso neutro e lasciando allo script l'unica strada di caricamento, compresa la creazione di
  `readonly`.
- **Copia raggiungibile dalla rete** — oggi la porta è pubblicata su `0.0.0.0:3307`. Su Ubuntu
  Docker scrive le sue regole di firewall e passa sopra a `ufw`, quindi la porta resterebbe aperta
  verso internet. Mitigato pubblicando solo su `127.0.0.1`. Le password di `root` e `readonly`
  restano nel compose: con la porta sul loopback le raggiunge solo chi ha già accesso al server o
  ai container di Forestas, quindi non proteggono nulla di più. A proteggere la copia dagli errori
  è il solo `SELECT` di `readonly`.
- **Dati personali nella documentazione** — la copia contiene email di utenti e moduli inviati.
  Mitigato: la pagina di conoscenza mostra solo struttura e contenuti pubblici.
- **Copia data per presente dove non c'è** — la riga nel `CLAUDE.md` la leggono tutte le sessioni,
  mentre la copia esiste solo dove è stata accesa e caricata. Mitigato: la pagina si apre con come
  verificare la copia locale, come accenderla e caricarla, e come raggiungere quella di UAT con il
  tunnel SSH.
- **Numeri che invecchiano** — i conteggi cambiano a ogni dump giornaliero. La pagina non riporta
  numeri: descrive la struttura (che cambia solo con un rilascio del codice Drupal) e le query, che
  si eseguono sulla copia appena caricata.

Considerati ipotetici e non mitigati: un file di dump troncato (lo script usa comunque
`set -euo pipefail`), il container rimosso da un `--remove-orphans` lanciato su `compose.yml`, un
import che legge dalla copia mentre la si ricarica (oggi nessun import la legge).

## Out of scope

- Riscrivere gli import esistenti per leggere dalla copia SQL al posto delle API
- Sync della copia fra ambienti (produzione → UAT, UAT → locale) e aggiornamento automatico
  notturno: vanno in un ticket generico di sync, da creare al termine di questo
- Il container della copia su UAT o in produzione: questo ticket riguarda solo l'ambiente locale
- Un comando artisan per eseguire query sulla copia
- Le query complete degli import attuali sulla copia e il confronto campo per campo con le
  risposte delle API: li farà il ticket che sposterà gli import dalle API all'SQL
- Avviare il sito Drupal: serve solo il database

## Moduli toccati

Tutto nel repo principale `forestas`, nessuna modifica a `wm-package`.

| File | Azione |
|---|---|
| `sardegnasentieri-dump.compose.yml` | modifica: cartella montata, utenti e permessi |
| `config/database.php` | modifica: host e porta di default della connessione `sardegnasentieri` |
| `scripts/drupal-dump-reload.sh` | nuovo |
| `docker/sardegnasentieri-init/01-readonly.sh` | rimosso |
| `docs/knowledge/copia-database-drupal.md` | nuovo |
| `CLAUDE.md` | una riga in `## Conoscenza` |
| `.claude/rules/import-sardegna-sentieri.md` | trappole sulla copia |
