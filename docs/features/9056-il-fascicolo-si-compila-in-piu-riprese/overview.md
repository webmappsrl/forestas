# oc:9056 — Il fascicolo si compila in più riprese

> Stato: approvata il 26/11/2026, dopo una lettura critica indipendente e le correzioni che ne sono
> seguite.

Branch: `feature/sus-2026-seconda-revisione`, negli stessi termini in `forestas` e in `wm-package`.
Confluisce in `develop` quando la famiglia di ticket del tag
`[SUS][FORESTAS][2026][SECONDA-REVISIONE]` è completa.

**Legenda delle sigle** usate in questa pagina:

- **SUS** — Sportello Unico dei Servizi della Regione Sardegna, il sistema che raccoglie le domande
  dei cittadini e le inoltra al catasto. È il client del canale `routes/sus.php`.
- **FORES_02** — il procedimento del SUS «Istanza di accatastamento sentieri nel catalogo RES», e
  il nome del format che lo descrive.
- **format** — il foglio Excel validato che elenca i campi del modulo, numerati da 1 a 74
  (`SUS-EVO_ANALOGHI_FORES_02_Format_Istanza accatastamento sentieri catalogo RES_v2.0.xlsx`).
  È la fonte autoritativa sui campi, sopra qualunque cosa detta in call.
- **DEM** — Digital Elevation Model, il servizio esterno che calcola i dati tecnici del tracciato
  (quota, dislivello, pendenza) a partire dalla geometria.
- **RES** — Rete Escursionistica della Sardegna.

## Cosa cambia

Oggi la domanda si consegna tutta in una volta: la creazione pretende ogni campo obbligatorio, e
dopo non si tocca più nulla. Diventa invece un fascicolo che **si apre con poco e si riempie in più
riprese**, come il modulo che il richiedente compila davvero.

- **La creazione chiede tre cose:** il **tracciato** (`geometry`), che serve a riservare il numero
  del catasto; il **riferimento della pratica del SUS** (`reference`), che serve a riagganciare i
  due fascicoli; e il **nome** (`name`), che resta obbligatorio perché è il titolo con cui la
  domanda si trova in Nova. Tutti gli altri campi diventano facoltativi in quel momento.
- **La patch riempie e corregge:** il SUS manda uno o più campi, e volendo un tracciato nuovo,
  finché la domanda è in compilazione.
- **La conferma è il guardiano unico:** lì si verifica che ci sia tutto *e* che tutto sia valido,
  valori e vincoli fra campi compresi. Una domanda che non passa quella verifica non entra in
  istruttoria.

### La creazione si può ritentare senza creare doppioni

`reference` è **univoco**, e una seconda creazione con un riferimento già presente non crea nulla:
risponde `422` dicendo che quel riferimento è già usato, **e nominando l'`id` della domanda che
esiste**.

Serve a un caso concreto: il SUS chiama la creazione, la rete va in timeout, la domanda sul catasto
è nata ma il SUS non lo sa e ritenta. Senza questo, nascono due domande, ciascuna con un numero del
catasto prenotato, e la seconda resta lì senza che nessuno possa chiuderla — l'annullamento è fuori
da questo ticket.

Con questo, quel `422` è esattamente l'informazione che manca al SUS: la domanda è nata, e si chiama
così. Da lì riprende con le patch, **senza dover cercare per un `id` nostro che non ha mai
ricevuto** e senza aspettare l'endpoint di ricerca.

Non si distingue fra un ritentativo onesto e un riferimento riusato per un altro tracciato: in
entrambi i casi la risposta è la stessa, perché in entrambi i casi la cosa giusta da fare è non
creare una seconda domanda. Lo standard chiama questa la strada di chi non calcola un'impronta del
contenuto — *«A resource that does not use fingerprints cannot detect a key reused with a different
payload, and handles such a request as a duplicate»*
([draft-ietf-httpapi-idempotency-key-header-09][idem], §2.4) — e la pagina della creazione cita quel
documento come riferimento.

[idem]: https://datatracker.ietf.org/doc/draft-ietf-httpapi-idempotency-key-header/

### La semantica della patch è quella di RFC 7396

La patch segue **RFC 7396 (JSON Merge Patch)**, lo standard che chi integra si aspetta, e la pagina
delle API lo cita come riferimento:

- un campo **assente non si tocca**;
- un campo **presente sostituisce** il valore di prima;
- un campo **vuoto cancella** il valore;
- gli **elenchi si sostituiscono per intero**: per cambiare un soggetto del ruolo gestore il SUS
  rimanda l'intera lista dei gestori, non il solo elemento.

**La cancellazione si fa con il campo vuoto, non con `null`.** È l'unico punto in cui ci si
allontana dalla lettera della RFC, e il motivo è il formato: la chiamata viaggia in
`multipart/form-data`, perché può portare il file del tracciato, e lì il `null` non esiste — ogni
campo è testo. Il nostro «cancella» è quindi il campo mandato senza valore:

```bash
curl -X POST https://…/api/v1/sus/trail-applications/42 \
  -F _method=PATCH \
  -F overlapping_routes=
```

La convenzione non è inventata per l'occasione: il codice già tratta la stringa vuota come «campo
non compilato» — `missingForConfirmation()` mette sullo stesso piano un campo assente, uno uguale a
`''` e uno uguale a `[]`.

**I campi obbligatori non si cancellano.** Una cancellazione su un campo che lo schema dichiara
`required` è rifiutata con `422`, dicendo quale campo e perché: un valore sbagliato su un campo
obbligatorio si corregge sostituendolo, non svuotandolo. Ne nasce un'asimmetria che va spiegata
nella pagina, perché a prima vista sembra un capriccio: un campo obbligatorio *mai compilato* è
ammesso, perché la creazione non lo pretende più, mentre uno *già compilato* non si può riportare a
vuoto — nel primo caso il campo non è ancora stato toccato, nel secondo si tornerebbe indietro su un
dato che prima della conferma dovrà comunque esserci.

### Cosa controlla la patch quando riceve un campo

La patch valida **l'unione** di ciò che è già salvato in `api_data` e di ciò che arriva, con le
stesse regole della creazione. Da questa regola discendono tre cose:

- un campo mandato con un valore non ammesso è rifiutato subito, mentre il funzionario sta ancora
  compilando;
- i `required` **non valgono alla patch**: un campo obbligatorio non ancora compilato non fa fallire
  la chiamata. È l'unica differenza dalla creazione, ed è il senso stesso del compilare in più
  riprese;
- se un campo della chiamata è sbagliato, la risposta è `422` con l'elenco e **non si scrive nulla**,
  neanche i campi validi della stessa chiamata. È il comportamento che la creazione ha già: la
  validazione gira prima del controller, e la transazione comincia dopo.

**I campi legati fra loro vanno mandati nella stessa patch.** Sono tre, e sono l'unico gruppo:
`activities`, `primary_activity` e `difficulty` — `primary_activity` deve essere una delle
`activities` dichiarate, e `DifficultyMatchesActivities` confronta `difficulty` con le stesse. Chi
ne manda uno solo lasciando gli altri com'erano ottiene un `422` se la combinazione non torna, e
deve rimandarli insieme. Tutti gli altri campi si mandano liberamente, uno per volta. **La pagina
delle API deve dirlo**: non è deducibile, e senza quella riga Engineering lo scopre provando.

### La chiamata è un POST con il metodo dichiarato nel form

La patch si chiama su `POST /trail-applications/{application}` con un campo `_method=PATCH` nel
modulo, non con un verbo `PATCH` vero.

**La motivazione va detta per quella che è, perché è cambiata.** Storicamente il parser di PHP
riempiva `$_POST` e `$_FILES` solo per il `POST`, e su `PATCH` e `PUT` il corpo
`multipart/form-data` restava grezzo in `php://input`: è la limitazione nota come bug #55815 del
bug tracker di PHP. **Su questo stack non è più vero:** il container gira PHP 8.4.27 e
`symfony/http-foundation` è 7.4.7, dove `Request::createFromGlobals()` chiama `request_parse_body()`
proprio per `PUT`, `DELETE` e `PATCH` (`Request.php:288-305`), quindi un `PATCH` vero in multipart i
campi e il file li riceverebbe.

Il method spoofing si tiene lo stesso, ma per un'altra ragione: `composer.json` dichiara ancora
`^8.2` nello shard e `>8.1` nel package, quindi il codice deve girare anche dove quel parsing non
c'è; ed è il meccanismo nativo di Laravel, coerente con il canale, che usa già `POST` per tutte le
scritture. La pagina delle API deve spiegare quel campo in più, altrimenti sembra un refuso — ma
**non deve dire che un `PATCH` vero non funziona**, perché su PHP 8.4 funzionerebbe.

## Perché

La creazione non è un inserimento: prenota un codice del catasto a partire dalla geometria e lancia
il calcolo DEM dentro la stessa transazione, e non è idempotente — il docblock dell'endpoint lo
dichiara: «due chiamate identiche creano due domande, ciascuna con il proprio `id`». Finché l'unica
porta è la creazione, ogni ripensamento del richiedente costa una domanda nuova, cioè un numero
bruciato e un'istanza morta che nessuno può chiudere.

La necessità è emersa nella call di chiusura del 23/11/2026 dal caso reale portato da Engineering:
l'istruttoria del SUS si chiude chiedendo al richiedente di correggere qualcosa, e la domanda sul
catasto deve recepire la correzione. Alessio Piccioli, distinguendo i due casi: «distinguiamo il GPX
da tutto il resto. OK, perché il GPX ci avrà una patch sua, tendenzialmente una patch da create o la
patch complessiva». E sul tracciato: «se l'istruttoria lato SUS viene chiusa negativamente perché
chiedono di ricaricare il tracciato GPX, allora qui la conseguenza è che ci sarà una patch anche per
il GPX… ma esattamente come fatto per la create, non è detto che il DEM arrivi contestualmente alla
chiamata perché è fatto con un servizio a parte, quindi lì ce l'hai nel `dem_status`… se ti arriva
un `processing` devi poi richiamarlo», con attese dell'ordine di un minuto.

**Perché `reference` nasce qui.** Alessio Piccioli, call tecnica del 19/11/2026: «l'unica cosa che
ci viene in mente è quello di aggiungere da subito un codice identificativo di scambio vostro…
allora quello lo aggiungiamo come parametro alla create obbligatorio assieme al GPX… noi ce lo
teniamo lato catasto e in più vi esponiamo una nuova API di ricerca». Risolve due problemi distinti:
la riconciliazione dopo un timeout, e il recupero dei dati oscurati per privacy — quesito 24: «tanto
il numero di pratica ce lo siamo tenuti nella prima chiamata della create, ci passate anche l'id
interno e quindi ce l'abbiamo». Qui nasce il campo; **l'endpoint di ricerca che lo interroga resta a
un ticket suo**, e finché non c'è, l'unica strada che il SUS ha per riagganciarsi a una creazione
andata in timeout è ritentarla — da cui [R3](#r3).

**Perché il nome resta obbligatorio.** Nel format **non esiste un campo per il nome del sentiero**:
la scheda «Dati sentiero» comincia dal campo 29 *Origine (da)* e il sentiero è identificato da
origine → destinazione. `name` è un'etichetta nostra — il `BodyParameter` della creazione lo dice
già: «Non è uno dei campi 29-65 del format» — e il SUS la compone per conto proprio. Toglierla dalla
creazione costerebbe più di quanto renda: in Nova `name` è `$title` della Resource ed è l'**unico**
campo di `$search`, quindi un'istanza senza nome è una riga vuota che il funzionario non trova
cercando; e siccome `name` non sta nello schema, non entra in `requiredApiFields()` e la conferma
oggi non lo guarda — una domanda senza nome passerebbe tutti i controlli.

**Perché la validazione piena sta alla conferma e non prima.** Le linee guida comuni sul PATCH
dicono il contrario: validare la modifica con lo stesso rigore di un aggiornamento completo. Qui si
è scelto diversamente, con cognizione. Finché l'istanza è `under_compilation` è una bozza nello
spazio del SUS, che nessuno legge se non chi la sta compilando — in call la divisione è stata
ripassata per intero: «vi diamo solo la persistenza, e Forestas ha chiesto di poter vedere lato
catasto che esiste un oggetto in bozza, ma non lo può toccare in nessuna maniera». Pretendere la
coerenza a ogni passo significherebbe impedire di compilare il modulo in più riprese, che è
esattamente ciò che questo ticket introduce. Il prezzo è che la verifica d'insieme alla conferma
deve esserci davvero, ed è lavoro nuovo: oggi non c'è.

Il momento in cui la bozza diventa definitiva non cambia — Moreno Santucci: «si aspettano una sola
conferma alla fine dell'istruttoria tecnica, quando la domanda diventa immodificabile».

## Punto di partenza

Letto sul codice il 25 e il 26/11/2026. I numeri di riga aiutano a cercare; quello che conta è il
contenuto descritto accanto.

**Le rotte del canale sono quattro, tutte `POST` o `GET`, in `routes/sus.php`:**
`POST /trail-applications`, `GET /trail-applications/{application}`,
`POST /trail-applications/{application}/attachments`, `POST /trail-applications/{application}/confirm`.
Nessuna `PATCH`, `PUT` o `DELETE`. Stanno in un gruppo con `auth:api`, il middleware
`LogSusRequest`, il ruolo `Sus` e un `throttle:30,1` per utente. **I controller sono tutti del
package**: lo shard espone le rotte e basta. La rotta nuova va nello stesso gruppo, e il commento
che spiega il conto del throttle va riscritto: oggi dice che «una domanda costa al più 11 chiamate»,
conto che con la compilazione in più riprese non vale più.

**L'obbligatorietà dei campi oggi nasce dallo schema e vale in due punti.**
`ApplicationSchema::requiredFieldNames()` raccoglie i campi dichiarati `required = true`, e da lì
passano sia le regole di validazione della creazione (`rules()`) sia il controllo di completezza del
modello (`TrailApplication::requiredApiFields()`). Con questo ticket i due usi si separano: **lo
schema resta la fonte unica di cosa è obbligatorio**, ma quell'obbligatorietà si applica *alla
conferma*, mentre creazione e patch validano la forma di ciò che hanno davanti. È la modifica
concettuale più importante del ticket e va scritta nei docblock, altrimenti chi legge `required`
nello schema si aspetta che la creazione lo pretenda. La stessa lista serve alla patch per rifiutare
la cancellazione di un campo obbligatorio.

**Le regole dei campi si riusano.** `StoreApplicationRequest::rules()` prende le regole da
`ApplicationSchema::rules()`, aggiunge gli intervalli di plausibilità dei campi numerici
(`PLAUSIBLE_RANGES`) e in coda due regole proprie, dichiarate fuori dallo schema
(`NON_SCHEMA_INPUT`): `'name' => ['required','string','max:255']` e
`'geometry' => ['required','file','max:5120']`. Dopo questo ticket `name` e `geometry` restano
obbligatori sulla creazione, la geometria diventa facoltativa sulla patch, e gli altri `required`
valgono solo alla conferma.

**La cancellazione va riconosciuta prima della validazione, e il valore da cercare è `null`.**
`TrimStrings` e `ConvertEmptyStringsToNull` sono middleware globali di default e `bootstrap/app.php`
non li toglie: **un campo mandato vuoto arriva all'applicazione come `null`, mai come `''`**. Quindi
il segnale della cancellazione è «chiave presente con valore `null`», e `prepareForValidation()`
nemmeno lo decodifica, perché la decodifica dei campi `json` scatta solo `is_string`. Su un campo
`nullable` quel `null` passerebbe la validazione e verrebbe scritto come valore, invece di
cancellare la chiave. Quindi «campo vuoto uguale cancella» va intercettato lì: il campo esce dal
payload da validare ed entra nell'elenco delle chiavi da togliere. Vale soprattutto per i campi
`json` e numerici, dove la stringa vuota non è un valore ammissibile. **Cancellare non è la stessa
cosa che mandare un elenco vuoto:** il campo vuoto toglie la chiave, `'[]'` scrive un elenco vuoto;
per la conferma sono equivalenti, nel dato che Forestas legge no, e la pagina deve dire quale usare.

**I vincoli fra campi oggi stanno in un posto da cui non si possono riusare.** Il controllo che
`primary_activity` sia fra le `activities` dichiarate e che `difficulty` copra le stesse attività
vive in `StoreApplicationRequest::withValidator()`, cioè dentro una FormRequest, che lavora
sull'input HTTP di quella chiamata. Alla conferma non c'è nessun input HTTP: c'è `api_data` letto
dal database. Per farli valere in entrambi i punti vanno **estratti in qualcosa che validi un
array** — un service o una Rule — e la creazione poi lo richiama. Senza questo passo la conferma non
può fare la verifica che questo ticket le affida. **Nel dominio non esiste oggi nessuna validazione
fuori da una FormRequest**: è codice nuovo, non uno spostamento. Nello stesso metodo c'è
`rejectUndeclaredKeys()`, che rifiuta le chiavi non dichiarate dallo schema e serve anche sulla
patch.

**La conferma oggi non valida i valori, solo la presenza.** `ConfirmApplicationController` chiama
`TrailRegistryService::confirmCompilation()`, che dentro una transazione rilegge la riga con
`lockForUpdate()`, controlla lo stato e invoca `$locked->missingForConfirmation()`: quel metodo
scarta i campi di `requiredApiFields()` assenti, uguali a `''` o a `[]`, e le collection di allegati
vuote, e se trova qualcosa solleva `ApplicationIncompleteException`, che il controller traduce in un
`422` con l'elenco di ciò che manca. Un campo *presente* con un valore impossibile — una difficoltà
che non esiste, un'attività fuori elenco, una data futura — oggi passa. Finora reggeva perché
l'unica porta era la creazione, che validava tutto; da qui in poi quella porta non c'è più, e **la
validazione completa alla conferma va scritta**, riusando il validatore estratto e le regole dello
schema. La forma della risposta `422` esiste già ed è buona: elenco strutturato di ciò che manca,
«così che lo sportello possa mostrare l'errore accanto ai campi giusti».

**La scrittura del payload oggi sostituisce, non fonde.** `TrailApplication::writeApiData()`
riscrive l'intera colonna `api_data` passando da SQL e non da Eloquent, di proposito: «`updated_at`
non va toccato: è una consegna dell'API, non una modifica dell'operatore, e il trafficCop di Nova
rifiuterebbe con 409 il salvataggio di chi ha aperto il form un attimo prima». Per la patch serve la
fusione del payload nuovo su quello esistente, con le chiavi cancellate tolte, e la versione dello
schema riscritta in coda come fa la creazione — «la stabilisce chi accetta, non chi consegna».

**Il riferimento della pratica del SUS non esiste.** Nessun campo `external_id` o simile in
`TrailRegistry` né nei controller del canale. Va come **colonna** della tabella e non come chiave
dentro `api_data`: deve essere indicizzata, unica, e va mostrata all'operatore Forestas in Nova,
perché è il suo aggancio alla pratica del SUS. La colonna si aggiunge **nello stub della creazione
della tabella**, `database/migrations/trail_registry/zz_2026_09_09_000001_create_trail_applications_table.php.stub`,
non con una migration aggiuntiva. Attenzione a non metterla in `properties`: il commento di quella
colonna spiega perché i dati del richiedente ne stanno fuori — «il GeoJSON del modello riversa
`properties` per intero nella Feature, e quella Feature viaggia verso il servizio DEM e verso il
sentiero pubblicato».

**Perché il nome del campo è `reference` e non `sus_*`.** Il package è condiviso, e **«SUS» lì non
esiste**: compare in quattro commenti e in nessun nome di classe, colonna o campo. SUS è lo sportello
della Regione Sardegna; un altro shard che monta il package non ce l'ha. Il concetto, nel package, è
generale — *il riferimento con cui il sistema esterno che ha mandato la domanda la chiama a casa
propria* — e si legge accanto alla colonna `source`, che dice già da dove la domanda arriva:
`source = api`, `reference = FORES_02/2026/000123`. Il nome `code` non era disponibile: nel dominio
è già il numero del catasto, composto da `region`, `province`, `area`, `sector`, `number`,
`variant`, ed è proprio il codice che va tenuto distinto da quello che manda il SUS. La parola
«SUS» resta dove già sta: nei testi della documentazione dello shard.

**La guardia sullo stato ha un pattern già in casa**, in `StoreAttachmentController`: dentro la
transazione rilegge la riga con `lockForUpdate()`, e se
`$locked->status !== TrailApplicationStatus::UnderCompilation` restituisce l'esito `'closed'`, che
diventa `$this->failure(…, 409)`. Il commento dice perché non basta un controllo fatto prima: «il
canale fa più chiamate ravvicinate e fra un controllo fatto prima e la scrittura la domanda potrebbe
essere stata trasmessa». Vale identico per la patch.

**L'appartenenza al canale ha già la sua classe.** `ApiChannelApplicationRequest` è una FormRequest
astratta il cui `authorize()` solleva `ModelNotFoundException` se l'istanza non ha
`source === 'api'`, così «una domanda d'ufficio e un identificatore sconosciuto escono dallo stesso
punto dell'handler con la stessa risposta, e non si possono distinguere scorrendo gli
identificatori». La usano lettura e conferma, che non hanno payload. La Request della patch ha
bisogno di entrambe le cose — le regole dei campi e il controllo del canale — e PHP non ha
ereditarietà multipla: **il controllo va estratto in un trait** condiviso, non duplicato, perché una
correzione futura ne aggiornerebbe una copia sola su un comportamento che esiste per non far
distinguere un'istanza altrui da una inesistente.

**Il tracciato si legge in un posto solo, e di quel file tiene solo le coordinate.**
`TrailGeometryReader::wktFrom()` scorre `trk → trkseg → trkpt` e `rte → rtept` per il GPX, e
`features → geometry` per il GeoJSON — FeatureCollection, Feature singola o geometria nuda — e
restituisce una stringa sola, il WKT. Il `<name>` del GPX e le `properties` del GeoJSON non vengono
letti, e questo ticket non cambia nulla qui.

**Cosa comporta il tracciato nella patch.** Tutto ciò che la creazione fa sulla geometria: validare
il file (la creazione distingue XML non valido, JSON non valido, nessuna linea utilizzabile e
formato sconosciuto, con `InvalidTrailGeometryException` e messaggi dedicati), riscrivere la colonna
con `ST_GeomFromText(?, 4326)::geography` da SQL puro — mai da Eloquent, che corromperebbe la
geometria — rileggere il modello perché il calcolo dei dati tecnici legge la geometria da lì, e
rifare il DEM con `ApplicationDemService::computeAndStore()`.

**Il DEM si ricalcola sempre, e per ottenerlo serve azzerare `dem_data`.** `computeAndStore()` non
guarda `needsDem()`: chiama il servizio e scrive, quindi sul percorso sincrono il ricalcolo avviene
comunque. Il controllo sta nel **job** di ripiego (`UpdateTrailApplicationDemJob:82`), e
`needsDem()` è falso quando `dem_data` esiste e la geometria ha quote non tutte a zero: con un GPX
nuovo che porta già le `<ele>`, il job uscirebbe senza fare nulla e la lettura risponderebbe
`computed` con i dati del tracciato precedente, perché usa lo stesso criterio. **Azzerando
`dem_data` nello stesso `UPDATE` della geometria il problema non si pone**: `needsDem()` diventa
vero per costruzione e il job ricalcola. Vanno fatti a mano anche il lock e l'accodamento del job:
oggi li prende l'hook `created` del modello, che una patch non fa scattare.

**Il file originale del tracciato va sostituito.** `original_geometry` è una collection a file
singolo ed è fra gli allegati obbligatori alla conferma; è anche ciò che viene copiato sul sentiero
all'approvazione. Una patch che cambia la geometria e lascia lì il vecchio file manda in istruttoria
due tracciati diversi.

**E il numero riservato va rivisto:** `TrailRegistryService::reserve()` non prende un numero
qualunque, legge la geometria dell'istanza (`$geometry = $this->wktOf($application)`) e da quella
propone il candidato, con motivazione `reserved_on_application`; un tracciato nuovo può cadere in un
settore diverso, quindi il numero va rilasciato e riprenotato. **Si fa con `release()` e `reserve()`,
non con `replaceNumber()`:** quest'ultimo copia `region`, `province`, `area`, `sector` e
`taxonomy_where_id` dal codice vecchio, quindi sostituisce il numero *dentro lo stesso settore* ed è
il gesto dell'operatore in Nova, non questo. `TrailApplication::codes()` è una `HasMany`, quindi la
sostituzione aggiunge una riga alla storia dei codici invece di romperne il legame. Se il settore
non cambia, `resolveSector()` è pubblico e basta a deciderlo senza toccare il numero. Restano i due errori che `reserve()` può sollevare: settore
inesistente (`SectorNotFoundException`) e settore esaurito (`SectorExhaustedException`).
L'operazione non ha conseguenze verso l'esterno: quel numero non è mai uscito, perché la lettura
espone il `code` solo a istanza `approved`, e l'assegnazione definitiva avviene all'approvazione.
Ha un'eco in call, Giuseppe Bonfanti il 19/11/2026: «il codice… è anche per questo che non lo
rilasciamo, perché se cambiate la geometria cambia anche il codice».

**La documentazione si scrive in più punti di quelli ovvi.** Nel package: gli attributi Scramble
(`#[BodyParameter]`, `#[Response]`) sul controller nuovo e su quello della creazione, che cambia, e
gli esempi `curl` dei loro docblock. **E i testi che oggi dicono che una domanda non si corregge**:
i docblock di `ConfirmApplicationController`, di `ShowApplicationController` e del modello
`TrailApplication`, che descrivono il ciclo vecchio e diventano falsi con questo ticket. Nello shard `forestas`: la prosa di `config/scramble.php`, che
descrive le chiamate del canale e il loro ordine, e `resources/views/vendor/scramble/docs.blade.php`,
dove il bottone di prova manda un modulo precompilato dalla costante `SAMPLE`. **In nessuno di
questi testi va scritto un numero di ticket**: chi legge la pagina non sa cosa siano, e la
motivazione si scrive per esteso.

## Requisiti

### <a id="r1"></a>**R1** — La creazione chiede tre campi

Il SUS apre una domanda mandando `reference`, `name` e `geometry`, e soltanto quelli: un campo del
modulo mandato all'apertura viene rifiutato con `422`. Il modulo si consegna con una o più
modifiche.

Non è una scelta di comodo: così ogni chiamata ha un compito solo — questa apre il fascicolo e
prenota il numero del catasto, la modifica lo riempie — e chi integra non sceglie fra due strade che
farebbero la stessa cosa.

*Fonte:* Alessio Piccioli, call tecnica del 19/11/2026 — «quello lo aggiungiamo come parametro alla
create obbligatorio assieme al GPX»; la permanenza di `name` è decisione del dev del 26/11/2026,
sul fatto che nel format il sentiero non ha una denominazione e che `name` è `$title` e unico campo
di `$search` della Resource Nova.

**Verifica:** una creazione con i soli `reference`, `name` e `geometry` risponde `201`, la domanda
nasce in `under_compilation` con il numero del catasto riservato e `api_data` con la sola versione
dello schema; una creazione che porta un campo del modulo risponde `422` nominandolo e non crea
nulla.

### <a id="r2"></a>**R2** — Il riferimento del SUS resta legato alla domanda ed è visibile

`reference` è una colonna della tabella, indicizzata e unica, e l'operatore Forestas la vede sulla
domanda in Nova.

*Fonte:* Alessio Piccioli, call del 23/11/2026, quesito 24 — «tanto il numero di pratica ce lo siamo
tenuti nella prima chiamata della create, ci passate anche l'id interno e quindi ce l'abbiamo».

**Verifica:** dopo la creazione la colonna contiene il valore mandato; la detail view della Resource
Nova lo mostra; un secondo inserimento diretto con lo stesso valore è rifiutato dal database.

### <a id="r3"></a>**R3** — La creazione si può ritentare senza creare doppioni

Una creazione con un `reference` già presente non crea nulla e risponde `422`, dicendo che quel
riferimento è già usato **e nominando l'`id` della domanda esistente**. Vale sempre, qualunque
tracciato porti e in qualunque stato sia la domanda trovata: il SUS riceve il nome del proprio
fascicolo e da lì prosegue con le patch.

**Il caso da reggere è la concorrenza, non la sequenza.** Un timeout non arriva mai a transazione
chiusa: il ritentativo parte mentre la prima chiamata è ancora dentro la sua transazione, che
comprende il calcolo DEM. Una ricerca fatta prima non vede ancora nulla, l'`INSERT` si blocca
sull'indice unico e, al commit dell'altra, fallisce con una violazione di unicità. **Quella
violazione va intercettata e tradotta nello stesso `422`**; se non lo si fa, il SUS prende un `500`
proprio nel caso per cui questo requisito esiste.

`reference` **non è un campo del modulo**: va in `NON_SCHEMA_INPUT` accanto a `name` e `geometry`,
altrimenti `apiData()` lo scriverebbe dentro `api_data`, dove non deve stare.

*Fonte:* decisione del dev del 26/11/2026 — «la idempotency-key sarebbe la chiave SUS… anche se va
in timeout non devono cercare per un id interno nostro ma con la loro»; «422, la create va chiamata
una volta sola per identificativo SUS»; e «la soluzione è unica: rigetti tutto dicendo che quella
reference c'è già», con l'`id` nella risposta.

**Verifica:** due creazioni con lo stesso `reference`, in sequenza, lasciano una sola riga in
tabella e un solo numero del catasto riservato, e la seconda risponde `422` con l'`id` della prima;
due creazioni **sovrapposte** danno lo stesso esito e nessuna delle due risponde `500`; `api_data`
non contiene la chiave `reference`.

### <a id="r4"></a>**R4** — La patch modifica solo i campi che riceve

Il SUS può mandare uno o più campi di una domanda in compilazione e modificare solo quelli: i campi
non mandati restano come sono, e un elenco si sostituisce per intero mandandolo tutto.

*Fonte:* Alessio Piccioli, call del 23/11/2026 — «distinguiamo il GPX da tutto il resto…
tendenzialmente una patch da create o la patch complessiva»; e call del 19/11/2026 — «se qua devi
dopo la create c'è la persistenza, chiami la patch tutte le volte che vuoi».

**Verifica:** una patch su un campo solo lascia invariati gli altri valori di `api_data`; una patch
su un campo elenco lo sostituisce per intero e non fonde gli elementi.

### <a id="r5"></a>**R5** — La patch valida l'unione, e un errore non scrive nulla

La patch valida l'unione di ciò che è già salvato e di ciò che arriva, con le regole della
creazione. I campi obbligatori non ancora compilati non fanno fallire la chiamata. Se un campo è
sbagliato la risposta è `422` con l'elenco, e nessuno dei campi della chiamata viene scritto.

**Decade solo il `required` del campo di primo livello.** Le regole annidate restano in vigore:
`managing_subjects.*.name` resta obbligatorio dentro ogni soggetto mandato, e i `required_if`
continuano a valere, perché dipendono da un altro campo e non dalla compilazione in più riprese. Un
elenco mandato a metà resta un errore.

**Dall'unione vanno tolte due chiavi** prima di passarla al validatore, altrimenti
`rejectUndeclaredKeys()` le boccia: `_method`, che è del protocollo e non del modulo, e
`_schema_version`, che è già dentro `api_data` perché l'abbiamo scritta noi.

*Fonte:* decisione del dev del 26/11/2026 — «deve funzionare come se fosse una create»,
sull'osservazione che la creazione con due campi sbagliati su dieci non scrive nulla.

**Verifica:** una patch con `distance=-5` risponde `422` e la colonna `api_data` resta identica a
prima, compresi i campi validi mandati nella stessa chiamata; una patch su un campo valido riesce
anche se metà dei campi obbligatori non è mai stata compilata.

### <a id="r6"></a>**R6** — I campi legati fra loro si mandano insieme

`activities`, `primary_activity` e `difficulty` vanno nella stessa patch quando la modifica li
riguarda: una combinazione incoerente è rifiutata con `422`. La pagina delle API lo dice
esplicitamente.

*Fonte:* decisione del dev del 26/11/2026 — «non è un problema nostro, significa che devono mandare
una patch sia con activities che primary activity nel payload».

**Verifica:** cambiare `activities` da sola, lasciando una `primary_activity` salvata che non vi
appartiene più, risponde `422`; mandare i due campi insieme riesce; la pagina delle API nomina i tre
campi.

### <a id="r7"></a>**R7** — Il campo vuoto cancella, i campi obbligatori no

Il SUS può cancellare il valore di un campo facoltativo mandandolo vuoto. La cancellazione di un
campo obbligatorio è rifiutata, dicendo quale campo e perché.

Il rifiuto riguarda il campo obbligatorio **già compilato**: mandare vuoto un campo obbligatorio
mai scritto non cambia nulla e non è un errore, altrimenti un form che rimanda tutti i suoi campi,
vuoti compresi, riceverebbe un `422` per ognuno di quelli non ancora compilati.

`difficulty` è un oggetto, e la RFC lo fonderebbe chiave per chiave: qui **si sostituisce per
intero**, come gli elenchi. È il secondo punto in cui ci si allontana dalla lettera della RFC, e va
scritto nella pagina accanto al primo.

*Fonte:* RFC 7396, che prevede la cancellazione nella patch; il divieto sui campi obbligatori è
decisione del dev del 25/11/2026.

**Verifica:** un campo facoltativo di testo, uno `json` e uno numerico mandati vuoti spariscono da
`api_data`; un campo obbligatorio già compilato mandato vuoto risponde `422` nominandolo; lo stesso
campo mai compilato, mandato vuoto, non produce errori e non scrive nulla.

### <a id="r8"></a>**R8** — Il tracciato si può sostituire, e i dati che ne dipendono seguono

Nella patch il SUS può mandare un tracciato nuovo. Quando lo fa, i dati che dipendono dalla
geometria tornano coerenti con essa: dati tecnici ricalcolati e numero riservato valido per il
settore in cui il tracciato ora cade. Il SUS sa se i dati tecnici sono già pronti o se deve
richiederli più tardi.

*Fonte:* Alessio Piccioli, call del 23/11/2026 — «non è detto che il DEM arrivi contestualmente alla
chiamata… se ti arriva un `processing` devi poi richiamarlo».

**Verifica:** una patch con un GPX in un settore diverso lascia il vecchio numero rilasciato, uno
nuovo riservato e la storia dei codici con entrambe le righe; la geometria in tabella corrisponde al
file mandato e `original_geometry` contiene il file nuovo; i dati tecnici sono quelli del tracciato
nuovo anche quando il GPX porta già le quote e il servizio DEM ha risposto solo al secondo giro; la
risposta porta `dem_status`.

### <a id="r9"></a>**R9** — La conferma verifica l'istanza per intero

Alla conferma l'istanza è verificata per intero — ci sono tutti i campi obbligatori e gli allegati,
i valori sono ammessi, i vincoli fra campi rispettati — e una domanda che non passa non entra in
istruttoria. Chi riceve il rifiuto sa quali campi correggere.

Con [R5](#r5) questo è una **rete di sicurezza, non la porta principale**: se la patch valida già
l'unione, un valore impossibile in `api_data` può arrivarci solo da una scrittura fatta fuori dal
canale. Resta perché costa poco e perché la conferma è l'ultimo punto prima dell'istruttoria.

La risposta `422` della conferma oggi ha una forma sola, `missing.fields` e `missing.attachments`:
**va estesa per dire anche quali valori non sono validi**, con la stessa struttura, perché lo
sportello li mostri accanto ai campi giusti.

*Fonte:* Moreno Santucci, call del 23/11/2026 — «si aspettano una sola conferma alla fine
dell'istruttoria tecnica, quando la domanda diventa immodificabile».

**Verifica:** una domanda il cui `api_data` è stato alterato nel database, con un'attività fuori
elenco, viene respinta alla conferma e la risposta nomina quel campo, distinguendolo da quelli che
mancano; oggi quella stessa domanda passa.

### <a id="r10"></a>**R10** — La patch vale solo in compilazione

La patch è ammessa solo su un'istanza in compilazione. Su un'istanza già trasmessa, respinta o
approvata risponde `409` senza scrivere nulla, e il controllo regge anche a chiamate ravvicinate.

**Verifica:** una patch su un'istanza `under_review` risponde `409` e non modifica `api_data`; la
riga viene riletta sotto `lockForUpdate()` dentro la transazione, come fa `StoreAttachmentController`.

### <a id="r11"></a>**R11** — Un'istanza fuori dal canale risponde come se non esistesse

Un'istanza che non è nata dal canale SUS risponde come un identificatore sconosciuto, e le due
risposte non si distinguono.

**Verifica:** una patch su un'istanza con `source = office` e una su un `id` inesistente danno la
stessa risposta, parola per parola.

### <a id="r12"></a>**R12** — La versione dello schema la scrive chi accetta

Dopo creazione e patch l'istanza porta la versione dello schema di chi ha accettato la chiamata, non
quella eventualmente mandata dal richiedente.

**Verifica:** una creazione e una patch che non nominano la versione dello schema la trovano
scritta in `api_data` con il valore corrente del package. Una chiamata che prova a dichiararla
riceve `422`, perché `rejectUndeclaredKeys()` rifiuta le chiavi che lo schema non prevede — è il
comportamento che il test «la versione dello schema la stabilisce chi accetta, non chi consegna»
già fissa per la creazione, e la patch lo eredita.

### <a id="r13"></a>**R13** — La documentazione descrive il ciclo nuovo

La documentazione delle API descrive il ciclo nuovo — si apre con tre campi, si completa in più
riprese, si conferma — in tutti i punti in cui la pagina si scrive. Cita **RFC 7396** come
riferimento della semantica della patch e il **draft IETF `Idempotency-Key`** come riferimento del
comportamento della creazione ripetuta, con il link a
`https://datatracker.ietf.org/doc/draft-ietf-httpapi-idempotency-key-header/`. Spiega che la
cancellazione si fa con il campo vuoto e non con `null` perché la chiamata è in
`multipart/form-data`, spiega il campo `_method`, e dice quali campi vanno mandati insieme
([R6](#r6)).

*Fonte:* richiesta del dev del 26/11/2026 — «best practice nella documentazione della create linka
la ietf che stai usando».

**Verifica:** la pagina Scramble, letta dall'inizio alla fine da chi non conosce il ticket, basta a
scrivere un'integrazione che apre una domanda, la completa in tre chiamate e la conferma; i due
riferimenti esterni sono link cliccabili; nessun numero di ticket compare nel testo.

## Rischi

**Il contratto con Engineering cambia due volte.** Non solo nasce una chiamata nuova: cambia anche
la creazione, che smette di pretendere i campi obbligatori e in più ne chiede uno nuovo,
`reference`, obbligatorio. **Le integrazioni già scritte smettono di funzionare**, perché quel campo
oggi non lo manda nessuno: è una rottura voluta e concordata in call, non un effetto collaterale, ma
va detta a Engineering nella pagina.

**L'obbligatorietà dei campi cambia significato senza cambiare dichiarazione.** Nello schema un
campo resta `required`, ma da qui in poi quel flag vale alla conferma e non alla creazione. È il
genere di cosa che si dimentica: va scritta nei docblock di `ApplicationSchema` e
`requiredApiFields()`, non solo qui.

**La validazione piena alla conferma è la parte nuova e la più delicata.** Senza di essa il ticket
apre una strada per cui un'istanza con valori impossibili entra in istruttoria. Non basta scrivere
il controllo: vanno prima estratti dalla FormRequest i vincoli fra campi, oggi legati all'input
HTTP, e nel dominio non esiste nessun precedente di validazione fuori da una FormRequest.

**Il campo vuoto che cancella è una convenzione nostra, e va trattata prima del validatore.** Se
arriva al validatore, su un campo `json` o numerico produce un errore di tipo invece di cancellare:
è il difetto più probabile di questa parte, e merita un test per ciascuno dei tipi di campo.

**Due creazioni sovrapposte sono il caso normale di [R3](#r3), non quello raro.** Il ritentativo
dopo un timeout arriva quasi sempre mentre la prima chiamata è ancora aperta, e una ricerca fatta
prima non lo intercetta: senza la cattura della violazione di unicità, il SUS riceve un `500` al
posto del `422`, proprio dove il requisito serve. È la parte di questo ticket in cui un test «in
sequenza» dà falsa sicurezza.

**I dati tecnici vecchi sopravvivono a un tracciato nuovo**, se la patch non azzera `dem_data`: il
job di ripiego si tira indietro e la lettura dichiara `computed` su numeri che non appartengono più
a quella geometria. È un difetto silenzioso — nessun errore, solo dati sbagliati — ed è il più
difficile da accorgersene.

**Il `_method` sembrerà un refuso a chi legge la pagina**, e qualcuno proverà comunque un `PATCH`
vero: va spiegato perché c'è, altrimenti arriva la segnalazione che «la patch non riceve i dati» —
che è esattamente quello che succede, perché PHP non parsa il multipart su quel verbo.

**Il tracciato nuovo può cadere in un settore esaurito o inesistente.** È l'unico caso in cui una
patch fallisce per una ragione che il richiedente non può correggere cambiando un campo: va risposto
in modo che il SUS lo sappia spiegare, e va documentato.

**Il throttle del canale va ripensato.** Il commento in `routes/sus.php` conta oggi 11 chiamate per
domanda; con la compilazione in più riprese il conto cambia, e un'istruttoria con diverse correzioni
può avvicinarsi al limite di 30 al minuto.

## Out of scope

**L'endpoint di ricerca per `reference`**: qui nasce il campo e diventa ricercabile, ma la chiamata
che lo interroga resta a un ticket suo, da scrivere dentro il tag dopo questo. Finché non c'è, il
timeout si risolve con [R3](#r3): il SUS ritenta la creazione.

**La sostituzione degli allegati e delle fotografie**, che passa dalla divisione della collection
`photos` in cinque collection a file singolo e ha un ticket suo.

**L'annullamento dell'istanza**, che non è una modifica ma una chiusura: richiede uno stato
terminale nuovo e il rilascio del numero prenotato.

**Qualunque modifica dopo la conferma**: da `under_review` in poi l'istanza è nello spazio di
Forestas e si tocca da Nova, non dal canale.

**ETag e `If-Match` sulla patch**, raccomandati in generale per non sovrascrivere modifiche
concorrenti: qui il client è uno solo e il lock sulla riga c'è già.

**Una `Idempotency-Key` vera**, cioè un identificatore della singola chiamata con la risposta
conservata e una politica di scadenza: non serve, perché `reference` identifica la pratica e copre
il caso del timeout ([R3](#r3)).

## Moduli toccati

- **package:** lo stub `database/migrations/trail_registry/zz_2026_09_09_000001_create_trail_applications_table.php.stub`
  — la colonna `reference`, indicizzata e unica
- **package:** `StoreApplicationController` e `StoreApplicationRequest` — la creazione che non
  pretende più i campi del modulo, accoglie `reference` e riconosce il ritentativo
- **package:** un controller nuovo per la patch, con i suoi attributi Scramble e l'esempio `curl`
- **package:** una Request per la patch, con le regole sull'unione, il tracciato facoltativo e il
  riconoscimento dei campi da cancellare prima della validazione
- **package:** l'estrazione del controllo di appartenenza al canale in un trait, condiviso con
  `ApiChannelApplicationRequest`
- **package:** l'estrazione dei vincoli fra campi da `StoreApplicationRequest::withValidator()` in un
  validatore su array, richiamato da creazione, patch e conferma
- **package:** `TrailApplication` — la fusione del payload parziale su `api_data` con le chiavi
  cancellate, e i docblock sull'obbligatorietà che ora vale alla conferma
- **package:** `TrailRegistryService::confirmCompilation()` — la validazione completa accanto al
  controllo di completezza, e `ConfirmApplicationController` per la forma del `422` che distingue i
  campi mancanti dai valori non validi
- **package:** `UpdateTrailApplicationDemJob` e il lock del ricalcolo — accodati a mano dalla patch,
  che non passa dall'hook `created`
- **package:** la Resource Nova dell'istanza — `reference` visibile all'operatore
- **package:** i test — creazione con i tre campi, creazione con un `reference` già usato (in
  sequenza e sovrapposta), patch di un campo, campo rifiutato senza scrivere nulla, campi legati, cancellazione
  di un facoltativo (testo, `json`, numerico), cancellazione di un obbligatorio rifiutata, elenco
  sostituito, patch con tracciato nuovo (dati tecnici e numero riservato), `409` su istanza
  confermata, `404` su istanza non del canale, conferma rifiutata per campo mancante e per valore
  impossibile
- **shard:** la copia pubblicata della migration, `database/migrations/2026_09_10_073030_zz_2026_09_09_000001_create_trail_applications_table.php`,
  che oggi è identica allo stub e va allineata. Lo stub esce subito se la tabella esiste, quindi
  **sugli ambienti già installati — locale, dev e UAT — le due colonne nuove vanno aggiunte a mano**:
  il passo va scritto nel piano, non scoperto al primo deploy
- **shard:** `routes/sus.php` — la rotta nuova e il commento del throttle
- **shard:** `config/scramble.php` — la prosa del canale, il ciclo nuovo, i riferimenti a RFC 7396 e
  al draft IETF, la spiegazione del `_method` e i campi da mandare insieme
- **shard:** `resources/views/vendor/scramble/docs.blade.php` — il modulo precompilato del bottone di
  prova
