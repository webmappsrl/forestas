# oc:9056 — Piano

**Stato:** scritto il 26/11/2026, in attesa di approvazione. Overview approvata dal dev il
26/11/2026.

I requisiti citati stanno in [overview.md](overview.md). I task sono in ordine di dipendenza: ogni
task dà per fatto quello prima. Dove un task tocca tutti e due i repo è scritto.

**`T1`, `T2`, … sono i numeri dei task**, nell'ordine in cui vanno fatti: `T1` è il primo.

---

## T1 — Le due colonne nuove sulla tabella

**Dove:** package, `database/migrations/trail_registry/zz_2026_09_09_000001_create_trail_applications_table.php.stub`;
shard, la copia pubblicata `database/migrations/2026_09_10_073030_zz_….php`, che va allineata allo
stub.

Si aggiungono alla **creazione della tabella**, non con una migration a parte:

- `reference`, stringa, unica, indicizzata — il riferimento della pratica del SUS
  ([R2](overview.md#r2)). L'indice unico è anche ciò su cui poggia [R3](overview.md#r3).

⚠️ La colonna dell'impronta del tracciato non si fa più: [notes.md](notes.md#niente-impronta-del-tracciato-la-creazione-ripetuta-si-rigetta-e-basta).

⚠️ La colonna è `nullable`, non «non nulla» come diceva questo piano:
[notes.md](notes.md#t1--le-due-colonne-sono-nullable-il-piano-diceva-non-nulla).

⚠️ L'obbligatorietà di `reference` passa da una chiave di configurazione spenta di default:
[notes.md](notes.md#t1--lobbligatorietà-di-reference-passa-dalla-configurazione).

Nessuna delle due va in `properties`: quella colonna finisce dentro la Feature GeoJSON che viaggia
verso il servizio DEM e verso il sentiero pubblicato.

**Attenzione agli ambienti già installati.** Lo stub esce subito se la tabella esiste, quindi su
locale, dev e UAT le due colonne non arrivano da sole: vanno aggiunte a mano. Il comando va scritto
in `notes.md` quando lo si esegue, e comunicato al team per UAT.

**Verifica:** su un database vuoto la migration crea le due colonne con il vincolo di unicità su
`reference`; un secondo inserimento diretto con lo stesso `reference` è rifiutato dal database.

---

## T2 — soppresso

Era l'impronta del tracciato dentro `TrailGeometryReader`. Non serve più: la creazione con un
riferimento già usato si rigetta sempre, senza confrontare niente.

⚠️ [notes.md](notes.md#niente-impronta-del-tracciato-la-creazione-ripetuta-si-rigetta-e-basta)

Il numero resta qui, vuoto, perché i task dopo non cambino nome a metà lavoro.

---

## T3 — La creazione chiede tre campi e non pretende più il modulo

**Dove:** package, `src/Http/Requests/TrailRegistry/StoreApplicationRequest.php`.

- `reference` entra in `NON_SCHEMA_INPUT` accanto a `name` e `geometry`, così `apiData()` non lo
  scrive dentro `api_data`, e in `rules()` come `['required','string','max:…']`;
- i `required` che vengono dallo schema diventano condizionati alla presenza del campo: `name` e
  `geometry` restano obbligatori ([R1](overview.md#r1));
- `rejectUndeclaredKeys()` continua a valere senza modifiche: `reference` ora è una chiave
  dichiarata, quindi passa.

⚠️ Il controllo sui campi legati di [R6](overview.md#r6) nasce qui e non in T6:
[notes.md](notes.md#t3--il-gruppo-di-campi-legati-si-controlla-già-alla-creazione).

⚠️ Tre test esistenti cambiano con il comportamento:
[notes.md](notes.md#t3--tre-test-esistenti-cambiano-perché-cambia-il-comportamento).

**Verifica:** una creazione con i soli `reference`, `name` e `geometry` risponde `201`; una senza
`reference` risponde `422`; `api_data` non contiene la chiave `reference`; una creazione che manda
un campo del modulo con un valore non ammesso viene ancora rifiutata.

---

## T4 — La creazione ritentata non crea doppioni

**Dove:** package, `src/Http/Controllers/Api/TrailRegistry/StoreApplicationController.php`.

Il caso da reggere è la **concorrenza** ([R3](overview.md#r3)).

- prima della transazione, una ricerca per `reference`: se la domanda c'è, `422` con l'`id`,
  qualunque tracciato porti la chiamata e in qualunque stato sia la domanda trovata;
- la ricerca però non basta, perché un ritentativo da timeout arriva mentre la prima chiamata è
  ancora aperta e non vede niente: l'`INSERT` fallirà con una violazione di unicità, e **la
  violazione va catturata e tradotta nello stesso `422`**, rileggendo la riga per metterci l'`id`.
  `TrailRegistryService` ha già in casa il modo di riconoscere una violazione di unicità
  (`isUniqueViolation()`), da riusare.

**Verifica:** due creazioni con lo stesso `reference` in sequenza lasciano una riga sola e un numero
solo riservato, e la seconda risponde `422` con l'`id` della prima; due creazioni **sovrapposte**
danno lo stesso esito e nessuna risponde `500`.

⚠️ I test sono scritti sulla regola, non sull'implementazione:
[notes.md](notes.md#t4--i-test-della-creazione-sono-scritti-sulla-regola-non-sullimplementazione).

---

## T5 — Il controllo del canale diventa riusabile

**Dove:** package, `src/Http/Requests/TrailRegistry/ApiChannelApplicationRequest.php` e un trait
nuovo accanto.

`authorize()` solleva `ModelNotFoundException` quando l'istanza non ha `source === 'api'`, così
un'istanza d'ufficio e un identificatore sconosciuto escono dallo stesso punto. La Request della
patch ha bisogno **sia** di quel controllo **sia** delle regole dei campi, e PHP non ha ereditarietà
multipla: il controllo si estrae in un trait, e `ApiChannelApplicationRequest` lo usa invece di
contenerlo ([R11](overview.md#r11)).

Non è un'aggiunta di comportamento: il codice che si sposta è lo stesso, e i test di lettura e
conferma devono continuare a passare identici.

**Verifica:** i test esistenti di `ShowApplicationEndpointTest` e `ConfirmApplicationEndpointTest`
passano senza modifiche.

---

## T6 — I vincoli fra campi escono dalla FormRequest

**Dove:** package, da `StoreApplicationRequest::withValidator()` a una classe nuova in
`src/TrailRegistry/`.

Oggi il controllo che `primary_activity` stia fra le `activities` e che `difficulty` copra le stesse
attività vive dentro una FormRequest e lavora sull'input HTTP. Serve in tre punti — creazione, patch
e conferma — e alla conferma non c'è nessun input HTTP, solo `api_data` letto dal database. Si
estrae in qualcosa che **validi un array** ([R6](overview.md#r6), [R9](overview.md#r9)).

Nel dominio non esiste oggi nessuna validazione fuori da una FormRequest: è il primo caso, e la
forma che prende qui farà da precedente.

La creazione poi lo richiama al posto del codice che aveva: il comportamento non cambia, e i suoi
test lo dimostrano.

**Verifica:** `StoreApplicationRequestTest` passa senza modifiche; la classe nuova, chiamata su un
array con `primary_activity` fuori dalle `activities`, restituisce l'errore sul campo giusto.

---

## T7 — La Request della patch

**Dove:** package, una Request nuova in `src/Http/Requests/TrailRegistry/`.

- usa il trait di T5 per il controllo del canale;
- riconosce la **cancellazione prima della validazione**: chiave presente con valore `null` — non
  `''`, perché `ConvertEmptyStringsToNull` è un middleware globale. Il campo esce dal payload da
  validare ed entra nell'elenco delle chiavi da togliere ([R7](overview.md#r7));
- rifiuta la cancellazione di un campo obbligatorio **già compilato**, mentre su uno mai compilato
  non fa nulla;
- costruisce l'**unione** di `api_data` e di ciò che arriva, e la valida con le regole dello schema
  più quelle di plausibilità, togliendo prima `_method` e `_schema_version`, che
  `rejectUndeclaredKeys()` boccerebbe ([R5](overview.md#r5));
- decade solo il `required` di primo livello: le regole annidate (`managing_subjects.*.name`) e i
  `required_if` restano in vigore;
- `geometry` è facoltativa, con le stesse regole di formato e dimensione della creazione.

**Verifica:** una patch con `distance=-5` risponde `422` e non scrive nulla; una patch su un campo
valido riesce con metà modulo ancora vuoto; un campo facoltativo mandato vuoto sparisce da
`api_data`; un obbligatorio già compilato mandato vuoto risponde `422`; un elenco mandato a metà
(un soggetto senza `name`) resta un errore.

---

## T8 — La fusione del payload sul modello

**Dove:** package, `src/TrailRegistry/Models/TrailApplication.php`.

`writeApiData()` oggi sostituisce l'intera colonna passando da SQL, di proposito, per non toccare
`updated_at` — il trafficCop di Nova rifiuterebbe con `409` il salvataggio di un operatore che ha
aperto il form un attimo prima. Serve la variante che **fonde**: il payload nuovo sopra quello
esistente, le chiavi cancellate tolte, e la versione dello schema riscritta in coda come fa la
creazione, perché la stabilisce chi accetta ([R4](overview.md#r4), [R12](overview.md#r12)).

Gli elenchi si sostituiscono per intero, e così anche `difficulty`, che è un oggetto: è il secondo
scostamento dalla lettera di RFC 7396 e va documentato.

Nello stesso file vanno corretti i docblock che oggi dicono che l'obbligatorietà vale alla
consegna: da qui in poi vale alla conferma.

**Verifica:** una patch su un campo lascia invariati gli altri valori di `api_data`; `updated_at`
non cambia; una chiave cancellata sparisce; `_schema_version` resta quella del package.

---

## T9 — Il controller della patch

**Dove:** package, un controller nuovo in `src/Http/Controllers/Api/TrailRegistry/`, e la rotta
nello shard.

Dentro una transazione, con il pattern già in casa in `StoreAttachmentController`: rilettura della
riga con `lockForUpdate()`, e se lo stato non è `under_compilation` si esce con `409` senza scrivere
nulla ([R10](overview.md#r10)). Il controllo fatto prima della transazione non basta: il canale fa
chiamate ravvicinate.

La rotta va nello stesso gruppo delle altre in `routes/sus.php` — `auth:api`, `LogSusRequest`, ruolo
`Sus`, `throttle:30,1` — e il commento che conta 11 chiamate per domanda va riscritto, perché con la
compilazione in più riprese quel conto non vale più.

**Da aggiornare nello shard:** `tests/Feature/Sus/SusApplicationRoutesTest.php` dice oggi «espone le
quattro rotte sotto il prefisso del canale» e verifica il limite di frequenza «su tutte e quattro le
rotte». Con la patch diventano cinque, e quei due test vanno estesi, non solo corretti nel numero.

**Verifica:** una patch su un'istanza `under_review` risponde `409` e non modifica `api_data`; una
patch su un'istanza con `source = office` e una su un `id` inesistente danno la stessa risposta,
parola per parola.

---

## T10 — Il tracciato nella patch

**Dove:** package, il controller di T9.

È il task con più effetti collaterali ([R8](overview.md#r8)). Quando arriva una geometria nuova:

- si legge e si valida il file con `TrailGeometryReader`, con gli stessi errori distinti della
  creazione;
- si riscrive la colonna con `ST_GeomFromText(?, 4326)::geography` **da SQL puro**, mai da Eloquent,
  che corromperebbe la geometria, e **nello stesso `UPDATE` si azzera `dem_data`**: così
  `needsDem()` diventa vero per costruzione e il job di ripiego ricalcola invece di tirarsi indietro;
- si rilegge il modello, perché il calcolo legge la geometria da lì;
- si chiama `ApplicationDemService::computeAndStore()`, e se non riesce si prende il lock e si
  accoda `UpdateTrailApplicationDemJob` **a mano**: l'hook `created` del modello, che oggi fa
  entrambe le cose, su una patch non scatta;
- si sostituisce il file in `original_geometry`, che è una collection a file singolo ed è un
  allegato obbligatorio alla conferma;
- si rivede il numero riservato: `resolveSector()` dice se il settore è cambiato; se è cambiato,
  `release()` e poi `reserve()` nella stessa transazione — **non `replaceNumber()`**, che copia il
  settore dal codice vecchio ed è il gesto dell'operatore in Nova. `codes()` è una `HasMany`, quindi
  resta la storia dei due codici;
- restano i due errori che `reserve()` può sollevare, settore inesistente ed esaurito: vanno
  tradotti in una risposta che il SUS sappia spiegare, come fa oggi la creazione.

**Verifica:** una patch con un GPX in un settore diverso lascia il vecchio numero rilasciato, uno
nuovo riservato e la storia con entrambe le righe; la geometria in tabella corrisponde al file e
`original_geometry` contiene il file nuovo; i dati tecnici sono quelli del tracciato nuovo anche
quando il GPX porta già le quote e il servizio DEM risponde solo al secondo giro.

---

## T11 — La conferma verifica anche i valori

**Dove:** package, `src/TrailRegistry/TrailRegistryService.php` (`confirmCompilation()`) e
`src/Http/Controllers/Api/TrailRegistry/ConfirmApplicationController.php`.

Oggi `missingForConfirmation()` guarda solo se un campo c'è. Accanto a quel controllo va la
validazione dell'intero `api_data` con le regole dello schema e il validatore di T6
([R9](overview.md#r9)).

La risposta `422` ha oggi una forma sola, `missing.fields` e `missing.attachments`: va estesa per
dire anche quali **valori** non sono validi, con la stessa struttura, perché lo sportello li mostri
accanto ai campi giusti.

Con [R5](overview.md#r5) questa è una rete di sicurezza, non la porta principale: un valore
impossibile in `api_data` può arrivarci solo da una scrittura fatta fuori dal canale.

**Verifica:** una domanda il cui `api_data` è stato alterato nel database, con un'attività fuori
elenco, viene respinta alla conferma e la risposta nomina quel campo, distinguendolo da quelli che
mancano.

---

## T12 — `reference` visibile all'operatore

**Dove:** package, `src/TrailRegistry/Nova/TrailApplication.php`.

Un campo di sola lettura sulla Resource, come `rejection_reason`: è l'aggancio dell'operatore
Forestas alla pratica del SUS ([R2](overview.md#r2)). Va messo dove si vede subito, non dentro uno
dei tab di dettaglio.

**Verifica:** la detail view mostra `reference`; il campo non è modificabile.

---

## T13 — La documentazione

**Dove:** package, gli attributi Scramble e i docblock dei controller; shard,
`config/scramble.php` e `resources/views/vendor/scramble/docs.blade.php`.

Copre [R13](overview.md#r13). Nel package:

- `#[BodyParameter]` e `#[Response]` sul controller della patch, con l'esempio `curl`;
- gli stessi sulla creazione, che cambia: `reference` obbligatorio, il resto facoltativo, e cosa
  succede a una creazione ripetuta;
- **i docblock che oggi dicono che una domanda non si corregge**: conferma, lettura e modello.

Nello shard, nella prosa di `config/scramble.php`:

- il ciclo nuovo — si apre con tre campi, si completa in più riprese, si conferma;
- **RFC 7396** come riferimento della patch, con i due scostamenti dichiarati: la cancellazione con
  il campo vuoto invece di `null`, perché la chiamata è in `multipart/form-data`, e `difficulty` che
  si sostituisce per intero;
- il **draft IETF `Idempotency-Key`** come riferimento del comportamento della creazione ripetuta,
  con il link a `https://datatracker.ietf.org/doc/draft-ietf-httpapi-idempotency-key-header/`;
- il campo `_method`, **senza scrivere che un `PATCH` vero non funziona**: su PHP 8.4 funzionerebbe,
  e lo teniamo per portabilità;
- i tre campi che vanno mandati nella stessa patch: `activities`, `primary_activity`, `difficulty`.

E il modulo precompilato del bottone di prova, nella costante `SAMPLE` della vista.

**In nessuno di questi testi va scritto un numero di ticket.**

**Verifica:** la pagina, letta dall'inizio alla fine da chi non conosce il ticket, basta a scrivere
un'integrazione che apre una domanda, la completa in tre chiamate e la conferma; i due riferimenti
esterni sono link cliccabili; nessun `oc:` compare nel testo.

---

## T14 — I test

**Dove:** package, `tests/Feature/TrailRegistry/`; shard,
`tests/Feature/Sus/SusApplicationRoutesTest.php` per la rotta.

- creazione con i soli tre campi;
- creazione senza `reference`, rifiutata;
- creazione con un `reference` già usato, in sequenza e **sovrapposta**: `422` con l'`id` della
  domanda esistente, e nessuna riga nuova;
- patch di un campo, gli altri invariati;
- patch con un campo non valido: `422` e niente scritto, nemmeno i campi buoni della stessa chiamata;
- patch sui tre campi legati, insieme e separati;
- cancellazione di un facoltativo nei tre tipi — testo, `json`, numerico;
- cancellazione di un obbligatorio già compilato, rifiutata; dello stesso mai compilato, no-op;
- elenco sostituito per intero;
- patch con tracciato nuovo: geometria, `original_geometry`, dati tecnici ricalcolati anche con GPX
  che porta le quote, numero riservato nel settore nuovo;
- `409` su istanza confermata;
- risposta identica fra istanza d'ufficio e `id` inesistente;
- conferma rifiutata per campo mancante e per valore impossibile.

**Prima di lanciare la suite va verificato l'isolamento del database**, come impone il CLAUDE.md del
repo: il database reale contiene dati importati da processi lunghi.

---

## Cosa resta da decidere prima di cominciare

- **Il branch.** Il ticket indica `feature/sus-2026-seconda-revisione` in entrambi i repo; oggi lo
  shard è su `feature/oc-9039-soggetti-dell-istanza` e il package è in HEAD detached. Va sistemato
  prima di T1.
- **Il throttle.** `30,1` per utente regge il conto nuovo? Con la compilazione in più riprese una
  domanda non costa più 11 chiamate. Si decide scrivendo il commento in T9.
