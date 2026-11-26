> Ticket: oc:8999
> Overview approvata il 2026-11-26 · impronta `745cc9ffb7f52e14a898a48eed14c2b3582fb3ec`

# Notes — Bozze di Drupal importate senza lo stato di bozza: l'import le deve saltare

## Modifiche ai requisiti

### 2026-11-26 — Indirizzi di WordPress: si fanno dopo il merge
- **Requisito:** [R4](overview.md#r4)
- **Effetto:** non fatto
- **Decide:** agente
- **Perché:** i `forget` si lanciano solo dopo il primo import senza bozze di ogni ambiente: sul dev
  dopo il merge in `develop`, su UAT dopo il merge in `main`. La procedura è nel piano (Task 6 e 7);
  l'esito va nel ticket, non nel repo, perché oc:8999 entra in `develop` con un merge solo.

## Divergenze dal piano, task per task

### Task 1: nodi in bozza fuori dagli elenchi

Il test confronta le chiavi come interi (`[10, 17]`), non come stringhe: PHP converte in intero le
chiavi numeriche dell'array che `changedList()` restituisce.

### Task 2: traduzioni in bozza fuori dal record

Il quarto test non può verificare che «l'italiano in bozza sparisce» per un nodo nato in inglese:
`languages()` quella traduzione la ignorava già, come le vecchie API. È diventato
`non toglie mai la lingua originale, anche quando non è l'italiano`, e controlla che l'originale
inglese resti sotto `it` ed `en`. `allItems()`, l'unico altro metodo che legge tutte le lingue, lo
usano solo i termini di tassonomia, non i nodi.

### Task 4: i testi dicono come funziona ora

In più rispetto al piano: una seconda trappola in `.claude/rules/import-sardegna-sentieri.md` (il
reindex di fine import non toglie le voci delle tracce sparite: lo fa
`PruneStaleTrackSearchDocumentsJob`), e le voci di «Perché così» e «Come ci siamo arrivati» nella
pagina sulla copia, che per questo lavoro è la pagina di conoscenza.

## Bug trovati

### 2026-11-26 — In locale la suite scrive sull'indice Elasticsearch locale

Preesistente, non di questo lavoro. Senza `.env.testing` la suite legge `SCOUT_DRIVER` dal `.env`
locale (Elasticsearch), mentre `.env.testing-example` ha `collection` e `phpunit.xml` non lo
imposta. La suite completa del 26/11 ha lasciato nell'indice locale `ec_tracks` 67 voci di prova e
ne ha tolta una vera (traccia 82). Ripulito con `PruneStaleTrackSearchDocumentsJob`, che così è
stato provato anche contro un Elasticsearch vero («tolte 67 voci senza traccia»), e la traccia 82
rimessa con `searchable()`: indice e DB a 170.

Nella stessa suite falliscono 48 test che in CI passano (PR di oc:9018, 25/11): 35 del client SUS
per `JWT_SECRET` assente e 5 di Nova e del registro che leggono variabili del `.env` locale. Gli 11
errori di PHPStan sui test SUS hanno la stessa origine; sui file di questo lavoro PHPStan è pulito.
In locale PHPStan si lancia con la cache in `/tmp` del container (configurazione temporanea che
include `phpstan.neon.dist`): sul disco esterno il `rename` della cache fallisce.

## Decisioni

### 2026-11-26 — Le bozze non entrano nello shard
- **Decide:** Giuseppe, scrum del 17/11 (call delle 08:30 e delle 14:36)
- **Perché:** evitare gli slug doppi sul sito; le bozze restano nella copia SQL di Drupal e, se il
  cliente le chiederà, si riaggiungono.
- **Scartato:** una gestione delle bozze nello shard o in Nova: lo shard non ce l'ha e nessuno l'ha
  chiesta.

### 2026-11-26 — Traduzioni in bozza fuori, nodo con l'originale in bozza fuori del tutto
- **Decide:** Rubens
- **Perché:** in Drupal `status` è per traduzione; nello shard ogni nodo è costruito attorno alla
  lingua originale (la chiave `it` porta sempre l'originale).
- **Scartato:** importare un nodo con l'originale in bozza e la traduzione pubblicata usando solo la
  traduzione: andrebbe costruito in modo diverso da tutti gli altri, per un caso che oggi non esiste
  (0 su UAT il 26/11).

### 2026-11-26 — Indice: cancellare le voci in più, non ricostruirlo
- **Decide:** Rubens, dopo la challenge
- **Perché:** il reindex di fine import riscrive già tutte le tracce; basta cancellare le voci il cui
  id non è più una traccia.
- **Scartato:** ricostruire l'indice su uno nuovo e scambiare l'alias (`scout:import` di Matchish):
  rifà ogni notte tutta l'indicizzazione, e se si interrompe dopo `CreateWriteIndex` lascia un indice
  di scrittura nascosto in cui finiscono le modifiche. Scartato anche svuotare l'indice prima del
  reset: la lista resterebbe vuota per tutto l'import.

### 2026-11-26 — `forget` sugli indirizzi puliti delle bozze, non sui `-2`
- **Decide:** Rubens, dopo la challenge
- **Perché:** `wmwp_detail_history_forget()` cancella la riga del percorso passato e toglie lo stato
  di attuale agli omonimi: sull'indirizzo della bozza il pubblicato prende quello pulito e il suo `-2`
  resta come 301.
- **Scartato:** `forget` sul `-2` del pubblicato (proposto all'inizio del dialogo): cancella la riga
  del pubblicato, e il vecchio link con `-2` darebbe 404.

### 2026-11-26 — Niente import in produzione; lo schedule orario non si tocca
- **Decide:** Rubens
- **Perché:** l'import serve solo a tenere dev e UAT allineati a Drupal; in produzione resterà
  l'ultima copia importata. Lo schedule orario di `routes/console.php` è lavoro di oc:8489: si
  documenta che in produzione va spento, non si toglie.
- **Scartato:** nascondere tracce e POI marcati `deleted_from_source` (serve solo con l'import
  orario); togliere lo schedule orario dal codice.

### 2026-11-26 — Verifica delle traduzioni in bozza solo con il test
- **Requisito:** [R2](overview.md#r2)
- **Decide:** Rubens
- **Perché:** su dev e UAT nessun nodo ha una traduzione in uno stato diverso dall'originale; creare
  un caso vero vorrebbe dire scrivere nella copia Drupal, in sola lettura e ricaricata ogni giorno.

### 2026-11-26 — Rilascio su UAT
- **Decide:** Rubens
- **Perché:** UAT segue il merge di `develop` in `main`; Rubens dice quando è fatto, e solo allora si
  fa il Task 7.

### 2026-11-26 — Tag
- **Decide:** Rubens: nessun tag nuovo su oc:8999.

### 2026-11-26 — Un commit solo per i Task 1-5
- **Decide:** Rubens, che ha chiesto il commit dopo la review interna approvata
- **Perché:** i cinque messaggi del piano descrivono un lavoro unico, e il branch entra in `develop`
  con uno squash.

### 2026-11-26 — Procedura di messa in opera nella howto di UAT, import a mano dopo il deploy
- **Decide:** Rubens
- **Perché:** chi farà il passaggio su `main` deve trovare cosa fare senza conoscere oc:8999: la
  howto di UAT è nell'indice «Procedure» del `CLAUDE.md`, e `deploy-automatico.md` la richiama fra
  i passi a mano. Sul dev l'import si lancia subito dopo il deploy, senza aspettare le 06:00.
- **Scartato:** lasciare i passi solo nei Task 6 e 7 del piano: chi lavora su `main` non apre le
  cartelle delle feature.

## Follow-up

Nessuno.
