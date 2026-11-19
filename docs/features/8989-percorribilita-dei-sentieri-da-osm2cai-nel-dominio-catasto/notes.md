> Ticket: oc:8989
> Overview approvata il 2026-11-16 · impronta `56a945b86c227fb6b08f578d13f8245ac85ef93c`

# Notes — Percorribilità dei sentieri su Forestas

Le decisioni di questo lavoro sono quasi tutte di dominio e stanno nel package, insieme alle
alternative scartate: `wm-package/docs/features/8989-percorribilita-dei-sentieri-da-osm2cai-nel-dominio-catasto/notes.md`.
Qui restano quelle che riguardano solo lo shard.

## Modifiche ai requisiti

Nessuna finora.

## Divergenze dal piano, task per task

Nessuna finora: il lavoro non è ancora cominciato.

## Bug trovati

Nessuno finora.

## Decisioni

### 2026-11-16 — I termini li dichiara il package, non il comando dello shard

- **Decide:** dev
- **Perché:** due elenchi che scrivono la stessa riga, divergendo, creerebbero due famiglie —
  entrambe a scelta singola, entrambe accese: l'API esporrebbe due blocchi e le card conterebbero
  due volte. Il comando dello shard continua a dichiarare le sue famiglie, senza questa.
- **Conseguenza:** `ConfigureTaxonomyTermsCommand` perde i due termini di terreno da
  `validation-status` e **non** guadagna la famiglia della percorribilità.

### 2026-11-16 — Prima si spostano i sentieri, poi si tolgono i termini

- **Decide:** dev
- **Perché:** togliere i due termini dall'elenco mentre 171 sentieri ci sono ancora attaccati li
  lascerebbe legati a righe fuori elenco, visibili nei filtri e nel Select ma non più dichiarate.
  Il comando di migrazione viene prima.
- **Scartato:** rinominare i termini per spostarli di famiglia. `renameIdentifiers()` salta la
  rinomina quando trova sia il vecchio sia il nuovo identifier — e il nuovo esiste già, perché lo
  crea il package — stampando un avvertimento in un log che nessuno legge e lasciando i sentieri
  dov'erano, senza errori.

### 2026-11-16 — I numeri veri sono 208 e 556, non 171 e 454

- **Trovato durante l'esecuzione**, lanciando `sardegnasentieri:move-walkability --dry-run` sul
  database locale: **208 sentieri** portano uno dei due termini di terreno e **556** restano senza
  percorribilità nota, su 764 tracce. L'overview cita 152 + 19 su 625, presi da Geohub app 32 al
  19/10/2026.
- **Effetto:** i numeri dell'overview e del ticket vanno corretti alla chiusura; il rischio che li
  segnalava come ordine di grandezza era fondato.

### 2026-11-16 — Una card con un componente Vue inesistente non dà errore: mostra il vuoto

- **Trovato dal dev**, guardando la dashboard: le card erano montate e i loro dati corretti, ma in
  pagina non compariva niente. Il `$component` che avevo dichiarato non esisteva come componente
  Vue, e Nova in quel caso non segnala nulla.
- **Risolto** registrandoli in `wm-package/resources/js/domains/trail_registry.js` con una render
  function, come già fa `trail-registry-notice-card`: il Vue che Nova carica è la build
  runtime-only e non compila template scritti come stringa, così non serve nessun bundle.
- **Da ricordare:** il file dello script del dominio è in cache nel browser; dopo averlo cambiato
  serve una ricarica forzata, o la card continua a non comparire anche quando il codice è a posto.

### 2026-11-16 — Il ricalcolo delle incongruenze si aggancia anche a un evento

- **Perché:** le anomalie si ricalcolano a fine import, ma un'incongruenza della percorribilità
  dipende anche da un dato che l'import non tocca. Girando solo lì, una percorribilità registrata
  alle dieci resterebbe segnalata come mancante fino al giro successivo.
- **Come:** il package emette `WalkabilityChanged` dal service, lo shard lo ascolta in
  `AppServiceProvider`. Il package non sa chi reagisce.

### 2026-11-16 — Il tab «Stato»: un solo posto per gli stati e lo storico

- **Trovato dal dev**, guardando la scheda: la percorribilità restava modificabile anche dal campo
  generico delle classificazioni, che scrive il pivot e basta — nessun evento, nessuna nota,
  nessun autore, nessun lock. Era la strada più comoda, e la più comoda vince: lo stato sarebbe
  cambiato senza storia, cioè il difetto di osm2cai che questo lavoro non vuole replicare.
- **Come si è chiuso:** la famiglia dichiara `properties->tab = 'Status'`, il campo generico si
  toglie (`makeTermFieldsForTaxonomies()` nella `EcTrack` del package) e al suo posto c'è un
  `Select` il cui `fillUsing` passa da `WalkabilityService`, con la nota accanto.
- **Dove sta tutto:** un tab solo, «Stato», con tre blocchi separati da un titolo — Stato
  validazione, Percorribilità, Storico. Lo storico vicino all'inserimento, non in una sezione in
  fondo alla pagina: chi registra un dato vuole vedere lì sotto cosa è stato registrato prima.
  Anche `validation-status` si sposta lì dal tab generale.
- **In detail ogni stato è un blocco** con valore, chi l'ha scritto, quando e con quale nota
  (`TrailStatusRenderer`, nel package). Lo stesso renderer serve le due famiglie: per la
  validazione mostra oggi il solo valore, e si riempirà da sé quando quella famiglia avrà un
  punto di scrittura che registra gli eventi.
- **`__('Validation')` non si può usare come chiave:** il filesystem del container non distingue
  le maiuscole, quindi la traduzione ricade su `lang/it/validation.php` e torna l'array delle
  regole di validazione invece di una stringa — `Tab::make()` riceve un array e la scheda va in
  500. La chiave del tab è `Status`.

### 2026-11-16 — Perché gli eventi della validazione non si vedono nello storico

- **Non sono nascosti: non esistono.** `TrailHistoryRenderer` legge `trail_status_events` senza
  filtrare la famiglia, quindi li mostrerebbe. Nessun percorso li scrive: l'unico punto di
  scrittura è `WalkabilityService`, che scrive sempre la famiglia della percorribilità, e l'import
  continua a fare detach/attach del termine di validazione sul pivot come prima.
- **Resta il follow-up** della validazione datata con autore: la tabella è già pronta a riceverla,
  perché `taxonomy_id` tiene insieme più classificazioni.

### 2026-11-16 — Le incongruenze non vanno nell'elenco delle anomalie

- **Trovato dal dev**, aprendo il link della card: `TrailRegistryAnomaly` è nata per l'import e la
  sua testata spiega cose che valgono solo per quelle righe — «le correzioni non si fanno da questa
  schermata, il dato appartiene alla piattaforma di origine». Un'incongruenza si risolve invece
  proprio da lì, inserendo il dato: mostrarla in quell'elenco dà a chi legge l'istruzione opposta
  a quella giusta.
- **Come si è chiuso:** `TrailRegistryAnomaly` è diventata **astratta** (modello, colonne, mappa,
  titolo, divieti; astratto il solo `noticeBody()`), e da lei scendono `TrailRegistryImportAnomaly`
  — chiave storica `trail-registry-anomalies`, filtro per tipo di anomalia, query che esclude
  `source = inconsistency` — e `TrailRegistryInconsistency`, con chiave, filtro e testata propri.
- **Nel menu** «Anomalie» è un `MenuGroup` con «Da import» e «Incongruenze».
- **Lo shard** estende la prima con `App\Nova\TrailRegistryAnomaly` (la provenienza «registro» di
  oc:8539, invariata) e la seconda con `App\Nova\TrailRegistryInconsistency`.
- **Da ricordare:** `filters()` non può essere dichiarato astratto, perché `Laravel\Nova\Resource`
  lo implementa già; la base lo lascia vuoto e ogni sottoclasse lo dichiara.
- **Era un difetto anche prima:** l'elenco delle anomalie mostrava le incongruenze già da quando
  `WalkabilityInconsistencyService` ha iniziato a scriverle, perché `indexQuery` non filtrava il
  `source`.

### 2026-11-16 — La terza card: distribuzione generica, non una card per famiglia

- **Chiesta dal dev**: la distribuzione degli stati di validazione accanto a quella della
  percorribilità.
- **Come:** `TaxonomyDistributionCard` conta i termini di una famiglia qualsiasi e riceve titolo,
  famiglia ed eventuale termine da escludere; `WalkabilityDistributionCard` è quella card con i
  parametri già decisi. Un solo componente Vue, che prende titolo e nota dal PHP: due componenti
  gemelli sarebbero divergiti alla prima modifica fatta su uno solo.

### 2026-11-16 — Anche la validazione passa dal service, e i termini di terreno escono dalle scelte

- **Chiesto dal dev:** nello storico non compariva la validazione, perché nessun percorso ne
  registrava gli eventi.
- **Come:** `TrailStatusService` scrive lo stato di una famiglia qualsiasi — lock, rilettura,
  evento, legame — e `WalkabilityService` gli delega fissando la propria famiglia. Delega e non
  eredita: le firme sono diverse di proposito (lì la famiglia non si passa) e in PHP una
  sottoclasse non può restringere così un metodo. Dal service passano ora il campo di Nova e
  l'import (`syncValidationStatus()`, come `syncWalkability()`).
- **Il termine mancante** resta trattato come prima: `missing_term` fra le anomalie e famiglia
  svuotata — il service sa portare da uno stato a un altro, non a nessuno.
- **I due termini di terreno** sono entrati in `RETIRED_TERMS`, un elenco nuovo accanto a
  `RETIRED`, che toglie famiglie intere. La rimozione si ferma se qualcosa li usa: un sentiero
  ancora legato, o un evento di stato che li nomina.
- **Uno dei due non si cancella, ed è giusto così:** le chiavi esterne di `trail_status_events`
  sono senza cascata, quindi un termine che compare nella storia resta. Dove sarebbe comparso a
  zero — il filtro e la card — si mostrano ora i soli identifier che
  `ConfigureTaxonomyTermsCommand::termIdentifiersOf()` dichiara.
- **Trovato strada facendo:** la select della validazione offriva ancora i due termini legacy, ed
  è così che un sentiero era finito in uno stato che non esiste più. Riportato a «Non verificato»
  con un evento di migrazione.

### 2026-11-16 — La scala della percorribilità: cosa viene da dove

- I quattro termini vengono dall'enum `IssuesStatusEnum` di osm2cai2 (`Unknown`, `Open`, `Closed`,
  `PartiallyClosed`), verificato nel codice della fonte. Il dettaglio della corrispondenza sta
  nella sezione «Percorribilità» di `wm-package/docs/resources/TrailRegistry.md`.
- **Per Forestas** due dei quattro erano già su Drupal, dentro `stato_di_validazione`:
  `percorribile` (tid 4187) e `non percorribile` (tid 4870). L'import li manda nella famiglia
  nuova e mette la validazione a «Non verificato», perché da quel valore non si ricava a che punto
  fosse la pratica.
- Gli altri due l'import non li produce: «Sconosciuta» è lo stato di partenza di tutti i sentieri
  senza informazione — oggi 554 su 765 in locale — e «Parzialmente percorribile» lo inserisce chi
  va sul posto.
- **La scala non è stata chiesta da Forestas** e può cambiare: i valori stanno in tabella, e
  aggiungerne o toglierne uno è una riga dove si creano. L'unica attenzione è sul valore che
  `wp-forestas` usa per il semaforo in home.

## Follow-up

- Ricontare i sentieri per stato sul database vero prima della migrazione: i numeri dell'overview
  (625, di cui 152 percorribili e 19 non percorribili) vengono da Geohub app 32 al 19/10/2026 e
  servono come ordine di grandezza, non come dimensionamento.
- Verificare dove vivono le fasce del filtro «Percorribilità» della ricerca pubblica e chi le
  cambia sui due ambienti.
- I valori di validazione già nel database non hanno un evento dietro: nel blocco del detail
  autore e data restano `—` finché quel sentiero non cambia stato almeno una volta. Una migrazione
  che scriva un evento iniziale per tutti è possibile, ma datarla sarebbe inventare: la data in cui
  qualcuno ha validato quel sentiero non è in nessun campo.
