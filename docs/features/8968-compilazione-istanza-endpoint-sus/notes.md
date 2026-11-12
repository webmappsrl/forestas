> Ticket: oc:8968
> Overview approvata il 2026-11-11 · impronta `b8887415509a9940b829ba07298616375af05096`

# Notes — La compilazione di un'istanza: la parte di forestas

Le note del dominio stanno nel package:
`wm-package/docs/features/8968-compilazione-istanza-endpoint-sus/notes.md`. Qui solo ciò che
riguarda lo shard.

## Modifiche ai requisiti

### 2026-11-11 — Le rotte sono quattro: c'è anche la lettura della domanda
- **Requisito:** [R1](overview.md#r1)
- **Effetto:** modificato
- **Decide:** dev
- **Perché:** il dev ha anticipato in questo ticket l'endpoint di lettura, che il tag 709 metteva
  nella macro area 7: vedi la
  [voce del package sulla lettura](../../../wm-package/docs/features/8968-compilazione-istanza-endpoint-sus/notes.md#2026-11-11--un-endpoint-per-leggere-la-domanda).
- **Testo nuovo:**
  > Le quattro rotte — creazione, lettura, upload di un allegato, conferma — in `routes/sus.php`,
  > dentro il gruppo autenticato che già esiste, puntate ai controller del package.

### 2026-11-11 — Il throttle vale anche per la lettura, con un conteggio unico
- **Requisito:** [R2](overview.md#r2)
- **Effetto:** modificato
- **Decide:** agente
- **Perché:** la lettura sta nello stesso gruppo delle altre tre rotte ([R1](overview.md#r1)).
  Senza un limitatore con nome Laravel conta per utente e non per rotta, quindi la lettura consuma
  lo stesso budget delle altre chiamate. Il limite è 30 chiamate al minuto: una domanda costa al
  più 11 chiamate (una creazione, fino a nove allegati — quattro documenti e cinque fotografie — e
  una conferma), quindi passano due domande al minuto contro le dieci al giorno stimate. Con i
  volumi del verbale del 10/09 — al massimo 30 domande al mese, picchi di 10 al giorno — chi segue
  le proprie domande interrogandole ogni pochi minuti resta molto sotto il limite.
- **Testo nuovo:**
  > **Un throttle sulle quattro rotte**, con un conteggio unico per utente. Senza, gli endpoint più
  > cari che avremmo — upload su S3 e chiamata sincrona al servizio DEM — nascerebbero senza limite
  > di frequenza.

### 2026-11-11 — Il canale autenticato è riservato al ruolo `Sus`, ping e refresh compresi
- **Requisito:** [R3](overview.md#r3)
- **Effetto:** modificato
- **Decide:** agente
- **Perché:** `RestrictSusClient` impedisce al client dello sportello di uscire dal proprio
  prefisso, ma non impedisce a nessun altro di entrarci: con il solo `auth:api` un utente
  qualunque delle app mobile avrebbe potuto presentare domande. Per `ping` e `refresh` vale lo
  stesso: con il solo `auth:api` il refresh consegnava un token nuovo a chiunque ne avesse uno
  della piattaforma, perché il segreto JWT è lo stesso.
- **Testo nuovo:**
  > I middleware sono quelli del canale — `RestrictSusClient`, appeso al gruppo `api` in
  > `bootstrap/app.php`, e `LogSusRequest` per rotta — più il ruolo `Sus` su tutto il gruppo
  > autenticato, `ping` e `refresh` compresi: il canale è riservato al suo client.

### 2026-11-11 — Risposte uniformi a chi non è il client, e un `404` senza dettagli interni
- **Requisito:** [R7](overview.md#r7)
- **Effetto:** aggiunto
- **Decide:** dev
- **Perché:** prima la ricerca della domanda veniva prima del controllo di ruolo, e chi non era il
  client riceveva `403` dove la domanda esisteva e `404` dove no: scorrendo gli id poteva contarle.
  Il `404` di Laravel diceva inoltre «No query results for model
  [Wm\WmPackage\TrailRegistry\Models\TrailApplication] 42», e il contratto collaudato avrebbe
  congelato un dettaglio interno. La riscrittura sta in `bootstrap/app.php`, perché quel `404`
  nasce dal route model binding prima che il controller sia chiamato; il controllo di chi chiama
  sta nello stesso punto. Il package dichiara del `404` solo la descrizione: il corpo lo scrive lo
  shard nel callback di Scramble.
- **Scartato:** spostare `RoleMiddleware` prima di `SubstituteBindings` con
  `prependToPriorityList()`. L'ordine mostrato da `gatherRouteMiddleware()` in tinker era quello
  voluto, ma sulle richieste HTTP vere l'autenticazione falliva per tutti, client compreso; e
  cambiare la priorità globale dei middleware era comunque la strada più invasiva.
- **Testo nuovo:**
  > Sotto `api/v1/sus/` chi non è il client del canale riceve la stessa risposta su ogni
  > indirizzo, `{error}` con il messaggio «Accesso non consentito: serve un token valido del client
  > dello sportello.»: `401` senza un token valido, `403` con il token di un altro utente. Il
  > `404` risponde `{error}` senza nominare classi interne, e una domanda d'ufficio e un id
  > inesistente ricevono lo stesso corpo.

## Divergenze dal piano, task per task

Nessuna da annotare qui: lo shard non ha un piano proprio, e le divergenze del Task 11 sono nelle
[note del package](../../../wm-package/docs/features/8968-compilazione-istanza-endpoint-sus/notes.md#task-11-le-rotte-del-canale).

## Bug trovati

- **Attenzione a provare a mano il canale sul database locale.** Durante le prove alcuni token di
  prova hanno smesso di autenticarsi dopo chiamate a `refresh` (la lista nera del JWT sta nella
  cache, che qui è sul database, e `optimize:clear` la svuota). La causa esatta non è stata
  isolata; i test automatici, con utenti e token creati da capo, sono il riferimento.

## Decisioni

- **2026-11-11 — `lang/it/validation.php`.** `APP_LOCALE` e `APP_FALLBACK_LOCALE` sono entrambi
  `it` e Laravel porta con sé solo l'inglese: ogni `422` del canale usciva con la chiave grezza
  (`"validation.required"` invece del messaggio), e chi integra da quella stringa non ricava quale
  regola abbia violato. Il file ha tutte e 136 le voci del file inglese del framework, nello stesso
  ordine. I messaggi `custom` del package restano: il loader unisce i percorsi registrati.
- **2026-11-11 — `resources/views/vendor/scramble/docs.blade.php`.** Copia della view del package,
  che Laravel fa vincere su quella di `vendor/` senza toccare `config/scramble.php`. Contiene tre
  cose:
  1. **la riparazione del wrapper di `fetch`** della view originale, che ricostruiva gli header con
     `const { headers = new Headers() } = options || {}` e così cancellava il `Content-Type` con il
     boundary del multipart: PHP riceveva un corpo che non sapeva separare e il «Try it» rispondeva
     `422` dicendo che mancavano anche i campi appena compilati. Ora la richiesta si costruisce con
     `new Request(input, init)`;
  2. **il token applicato da solo** alle chiamate del Try It dopo il login, perché il «copia» di
     Stoplight copia sempre l'esempio intero e il token andava ritagliato a mano dal JSON;
  3. **un bottone che invia una domanda completa di esempio**, con i valori del `curl` della
     documentazione e un tracciato generato al momento: la creazione ha 17 campi obbligatori e
     compilarli a mano ad ogni prova non è ragionevole.

  Quando Scramble aggiorna la propria view, questa copia va riallineata a mano.
- **2026-11-11 — Appiattimento dell'`allOf` del corpo in `AppServiceProvider`.** Scramble produce
  un `allOf` quando un endpoint ha sia una Form Request sia gli attributi `#[BodyParameter]`;
  Stoplight Elements mostra i campi di un `allOf` ma non li raccoglie nel FormData. I due blocchi
  hanno le stesse 29 proprietà e gli stessi 17 campi obbligatori, e il secondo porta in più
  descrizioni ed esempi: tenere solo quello non toglie nulla al documento.
- **2026-11-11 — Le risposte d'errore dei middleware sono documentate in `AppServiceProvider`.**
  `401` da `auth:api`, `403` da `role:Sus`, `429` da `throttle`: Scramble non le vede, o le vede
  senza descrizione. Si ricavano dai middleware che ogni rotta ha davvero, con i corpi che Laravel e
  Spatie producono senza debug, e il `429` elenca le chiamate che condividono il conteggio, perché
  un limitatore senza nome conta per utente su tutte le rotte che ne hanno uno. Una risposta già
  descritta da un controller non viene sovrascritta: il `401` del refresh spiega anche la finestra
  di rinnovo, quello generico no.
- **2026-11-11 — Il ping non dichiara più un `403`.** Lo prometteva per un utente che non è il
  client SUS, ma il ping accettava qualunque utente autenticato: la documentazione diceva una cosa
  che il codice non faceva.
- **2026-11-12 — Il log del canale dice perché un `422` è fallito.** `LogSusRequest` aggiunge alla
  riga `validation_errors` (i campi con i loro messaggi), oppure, alla conferma, `missing` (campi e
  allegati che mancano) ed `error`. È la parte di
  [R6](../../../wm-package/docs/features/8968-compilazione-istanza-endpoint-sus/overview.md#r6) del package
  che riguarda il log, rimasta fuori fino a qui. Mai i valori inviati, che contengono nomi, e-mail e
  codici fiscali: si legge solo il corpo della risposta, che nomina i campi senza ripeterne il
  contenuto, e non la richiesta, i cui file a fine catena sono già stati spostati.

## Follow-up

Nessuno.
