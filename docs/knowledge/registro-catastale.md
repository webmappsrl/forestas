# Registro catastale

Mirror in piattaforma del foglio Google che Forestas condivide con il CAI: un registro storico dei
codici del Catasto Sentieri, tenuto a mano, che il cliente vuole smettere di aggiornare ma non
perdere. Per lunghezza, tempi, meta intermedia e i capi mancanti è anche la sorgente dei valori sul
sentiero (oc:8540, vedi «Scrittura sui sentieri»).

## Come funziona oggi

**Quando parte.** Alla fine della catena `sardegnasentieri:import`, ora sempre raggruppata in un
`Bus::batch()` sia con `--reset` sia nel regime orario, la callback del batch (`dispatchImportBatch()`
in `ImportSardegnaSentieriCommand`) accoda `ImportRegistroCatastaleJob` sulla coda dedicata
`sardegnasentieri-import` — ma la condizione dipende dal regime:

- con `--reset`, solo dopo un `normalize` del Catasto riuscito (soglia dei job falliti non
  superata): un archivio appena troncato e ancora incompleto produrrebbe anomalie del registro
  false;
- nell'import incrementale (regime orario, senza `--reset`), **sempre**, a ogni giro: lì non c'è
  stato nessun troncamento da ricalcolare prima, quindi non c'è un normalize da attendere. Vale
  anche quando da Sardegna Sentieri non arriva nulla di nuovo e non c'è nessun job da accodare:
  senza batch, il comando accoda il job del registro direttamente, perché il foglio Google cambia
  per conto suo;
- con `--only` (catasto parziale, `$withNormalize` false), **mai**, in nessuno dei due regimi;
- con `--reset` e nessun job, **mai**: un archivio troncato e non ripopolato non va specchiato.

Il job ha un `timeout` di 300 secondi, sotto i 360 del supervisor
`supervisor-sardegnasentieri-import`, e non si sovrappone a se stesso (middleware
`WithoutOverlapping` con chiave fissa): un giro che trova il lock occupato si scarta, il prossimo
import ne accoda un altro. A fine giro scrive sul canale `import` l'elenco di **tutti** i fogli
letti — nome, gid, righe, se è stato riconosciuto come registro — così un foglio che smette di
essere riconosciuto (colonna chiave rinominata) si vede dal log (oc:8539).

Il comando `forestas:registro-import` rilancia solo questo passo a mano, per una prova in locale o
un recupero manuale (oc:8539).

**Lettura del foglio.** `RegistroCatastaleSheetReader` scarica ogni giro l'elenco dei fogli dalla
pagina pubblica `htmlview` del file Google: non è un'API documentata, l'elenco è dentro un
`<script>` come array JS (`items.push({name, pageUrl, gid})`), decodificato a mano (`\/`, `\"`,
`\xHH`). Ogni foglio si scarica poi con l'export CSV pubblico (nessuna credenziale Google). Se la
pagina `htmlview` non elenca nessun foglio, un foglio non risponde con un CSV, Google risponde con
un errore anche dopo il ritentativo o la connessione cade, l'import si ferma senza toccare nulla:
ogni guasto diventa una `RegistroCatastaleReadException`, che job e comando scrivono in una riga
sul canale `import`, e l'eccezione arriva prima di qualunque scrittura (oc:8539).

**Riconoscimento e parsing.** `RegistroCatastaleRowParser` riconosce un registro dalle quattro
colonne chiave (area, `SETTORE`, `Numero`, link a Sardegna Sentieri), cercate per intestazione
normalizzata (senza spazi, maiuscole, punteggiatura) e non per lettera: i fogli reali non hanno le
stesse colonne nella stessa posizione. Ogni riga si salva come elenco ordinato di coppie
(intestazione, valore), non come mappa fissa: un'intestazione ripetuta (Gallura, «Comuni di
appartenenza» due volte) non perde il secondo valore. L'area eredita quella della riga sopra solo se la cella è **vuota**: una cella scritta ma
ambigua o illeggibile (es. `Z-SU-D Z-CA-C`, due lettere d'area diverse) resta senza area;
il numero si pulisce da note e punteggiatura e la lettera finale, se non seguita da un'altra
lettera o da un punto, è la variante (oc:8539).

**Aggancio riga → codice.** `RegistroCatastaleMatcher` prova prima il link: `/node/<id>` verso
`properties->forestas->source_id`, altrimenti l'URL parlante normalizzato (schema, `www`, barra
finale, decodifica, prefissi `/en/` e `/index.php/` ignorati) verso `properties->forestas->url`.
Se il link è vuoto o non porta a nessuna traccia, ripiega su area + settore + numero + variante,
**senza provincia** (nel catasto la provincia si ricava dalla geometria, nel foglio è quella
storica). Un link rotto ma un numero che il ripiego trova resta **consistente**, senza anomalia:
è la regola del ripiego approvata nell'overview (oc:8539).

**Tutto o niente.** `RegistroCatastaleImporter` scarica e valida tutti i fogli, poi svuota e
riscrive `registro_catastale_rows` in un'unica transazione. Se un solo foglio fallisce, il mirror
del giro precedente resta intatto. Vale anche per un foglio di registro con una colonna chiave
duplicata (due colonne «Numero», per esempio): il parser non sceglie a caso quale leggere e ferma
l'intero import, non solo quel foglio. È una scelta voluta — un mirror parziale sembrerebbe
completo — e il costo è che un errore di impaginazione su un foglio blocca l'aggiornamento di
tutti, finché qualcuno non lo corregge (oc:8539).

**Anomalie prodotte** (provenienza `registro`, distinta da `catasto`):

- `NUMERO_DIVERSO` — traccia agganciata il cui codice ha numero, settore, area o variante diversi
  dal foglio. Il contesto porta `code`, il codice completo del catasto nella forma di
  `TrailRegistryCode::code` (es. `ZSSG506C`), e `sheet_code`, come la riga si legge sul foglio
  senza regione e provincia (es. `G602C`).
- `LINK_ORFANO` — link che non porta a nessuna traccia (righe sfasate, slug vecchi, nodi
  inesistenti).
- `TRACCIA_SENZA_CODICE` — traccia agganciata senza codice attivo nel catasto, solo se quella
  traccia non ha già un'anomalia di provenienza `catasto` (altrimenti sarebbe un duplicato dello
  stesso avviso).
- `RIPIEGO_AMBIGUO` — il ripiego per area+settore+numero+variante trova più di un codice
  candidato. Il contesto porta in `candidates` i codici completi, non gli id.
- `RIGHE_MULTIPLE` — più righe del foglio cadono sullo stesso codice (di solito tratti diversi
  dello stesso numero, «prima parte» e «seconda parte»). Resta agganciata solo la riga trovata dal
  link, se è una sola; altrimenti nessuna. Il contesto porta `code` e in `rows` le coppie
  foglio/riga coinvolte; il sentiero non riceve valori dal registro (oc:8540).
- `VALORE_NON_SANITIZZABILE` — una cella di lunghezza o tempi che il sanitizzatore non legge, o
  che chi l'ha compilata segna come dubbia (`?`, un secondo orario nel testo). Il contesto porta
  `column` e `value` grezzo. Il valore in tabella è `registro_valore_illeggibile`: la colonna
  `type` è `varchar(32)` e il nome lungo non ci stava (oc:8540).

Le righe con link vuoto e nessun candidato nel ripiego **non** sono anomalie: sono numeri
prenotati, senza scheda su Sardegna Sentieri, per accordo esplicito col cliente (oc:8539).

**Scrittura sui sentieri** (oc:8540). Nella stessa transazione del mirror, per ogni sentiero con
una sola riga agganciata e senza `RIGHE_MULTIPLE`, `RegistroCatastaleTrackWriter` porta sul
sentiero alcune colonne, cercate per intestazione normalizzata:

| Colonna | Destinazione | Regola |
|---|---|---|
| «Origine (da)», «Destinazione (a)» | `properties.from`, `properties.to` | solo se il campo è vuoto: vince Drupal |
| «… Meta intermedia» | `properties.via` (chiave del package) | sempre, se la cella è piena |
| «lunghezza (m)» | `manual_data.distance`, in km | sempre, se sanitizzabile |
| «T. percorrenza (A)», «(R)» | `manual_data.duration_forward`, `duration_backward`, in minuti | sempre, se sanitizzabili |

- I testi si riducono a una riga (ritorni a capo e spazi ripetuti diventano uno spazio): il
  pannello «Proprietà» di Nova usa un input a riga singola e li perderebbe al primo salvataggio.
- `RegistroValueSanitizer` legge le varianti reali del foglio, una regola per variante, ciascuna
  con il suo test in `tests/Unit/RegistroCatastale/RegistroValueSanitizerTest.php`. Un tempo scritto
  come intero senza unità si legge in ore o in minuti secondo la velocità a piedi della riga
  (1–6 km/h); se non si decide, è illeggibile.
- Una cella vuota o illeggibile non scrive nulla: per quel campo vale il DEM. Una cella svuotata sul
  foglio non svuota il valore già scritto sul sentiero.
- Gli altri campi di `manual_data` (dislivelli, quote) restano degli operatori.
- Un valore uguale a quello già salvato non si riscrive, quindi `updated_at` cambia solo quando
  cambia il dato. `updated_at` si scrive nel fuso dell'app, non in quello della sessione PostgreSQL.
- Le celle segnaposto (`-`, `?`) si scrivono come testo qualsiasi: la correttezza del foglio è di
  chi lo compila.

**Interfaccia Nova.** `App\Nova\TrailRegistryCode` aggiunge alla scheda del codice una Tab
«Registro» (`RegistroTabRenderer`) con le colonne del foglio in righe; se la riga del codice è
finita in anomalia, la Tab mostra un avviso con link all'anomalia invece della tabella.
L'anomalia si cerca per traccia del codice, per `context.code` o fra i `context.candidates`, tutti
nella forma di `TrailRegistryCode::code`. Il link dal dettaglio dell'anomalia è cliccabile solo se
schema (`http`/`https`) e host (`sardegnasentieri.it` o un suo sottodominio) sono davvero quelli
di Sardegna Sentieri: la cella è testo libero del foglio.
`App\Nova\TrailRegistryAnomaly` distingue le due provenienze con un filtro
(`App\Nova\Filters\TrailAnomalySourceFilter`) e adatta titolo e testo secondo la provenienza
(oc:8539). Limite noto: quando in un `RIGHE_MULTIPLE` resta agganciata la riga del link, la Tab
mostra quella riga e non l'avviso, perché la riga ha la precedenza sull'anomalia; che il sentiero
non riceva valori si legge solo nella lista anomalie (oc:8540).

## Perché così

- **Sul numero del sentiero Drupal resta la fonte di verità.** Il foglio è compilato a mano e può
  sbagliare; il mirror segnala le discrepanze di numero, non le corregge (oc:8539).
- **Su lunghezza e tempi vince il registro, fino al go-live** (oc:8540). I valori di Drupal
  sono già ignorati ([8641](8641-import-sardegna-sentieri-non-scrive-manual-data.md)); quelli del
  foglio sono misurati sul campo, e per quei campi la call del 14/09/2026 ha fissato «o il Dem o
  l'Excel» (Piccioli). A ogni giro il registro sovrascrive anche una correzione fatta in Nova: chi vuole
  cambiare un valore lo cambia sul foglio. Dal go-live il foglio non si sincronizza più e i valori
  si correggono in Nova; lo spegnimento della sincronizzazione non fa parte di oc:8540.
- **Su origine e destinazione vince Drupal**: il registro riempie solo i campi vuoti, come testo,
  senza creare POI («è presente già su Drupal, lasciamo quello che abbiamo trovato su Drupal»,
  Piccioli, 14/09/2026) (oc:8540).
- **Update atomici sulle sole chiavi del registro, non il salvataggio del modello** (oc:8540): la
  catena DEM salva l'intero `properties`, e due processi che lo fanno insieme si cancellano i valori
  a vicenda (`wm-package/docs/knowledge/dati-dem-e-valori-manuali.md`, oc:8660). Un lock condiviso è
  stato scartato: `Bus::chain()` non rispetta `ShouldBeUnique` e il lock di oc:8660 vale solo
  all'accodamento. Resta il verso opposto: una catena DEM o un salvataggio da Nova che hanno letto
  il sentiero prima del giro del registro possono cancellarne le chiavi, che il giro dell'ora dopo
  riscrive.
- **Una sola riga per codice, e nessun valore finché è `RIGHE_MULTIPLE`** (oc:8540): tratti diversi
  dello stesso numero finivano sullo stesso sentiero per il ripiego; scegliere o sommare vorrebbe
  dire indovinare. Il link è la prova più forte, il numero solo un'ipotesi.
- **Ciò che non si risolve si esplicita** (oc:8540): un valore dubbio non si indovina, diventa
  un'anomalia che Forestas corregge sul foglio.
- **Mirror sul codice del catasto, non sulla traccia**, perché il registro è un elenco di numeri:
  la scheda del codice è dove lo cerca chi lavora sul catasto (oc:8539).
- **Lettura dinamica delle colonne**, non una mappa fissa: i fogli delle diverse aree non hanno le
  stesse colonne nella stessa posizione, e il foglio Gallura ripete un'intestazione (oc:8539).
- **Foreign key fra riga e codice**, non un riferimento libero: la tabella si svuota da sola con
  il reset notturno dei codici e si ricostruisce nella stessa catena, senza un passo di pulizia a
  parte (oc:8539).
- **Il job del registro gira come job separato dopo il normalize**, non dentro la stessa callback:
  la callback del batch non deve allungarsi oltre il timeout della coda
  (`docs/knowledge/import-asincrono-e-anomalie.md`), e un foglio Google irraggiungibile non deve
  bloccare il ricalcolo delle anomalie del catasto (oc:8539).

## Come ci siamo arrivati

Scelte iniziali superate durante l'esecuzione, a beneficio di chi rilegge il ticket:

- **«Drupal resta l'unica fonte di verità», il foglio si specchia e basta** (oc:8539, superata
  in oc:8540): valeva finché il registro era solo un mirror. Con oc:8540 il foglio scrive sui
  sentieri lunghezza, tempi, meta intermedia e i capi mancanti, perché per quei dati Drupal non ha
  valori affidabili o non li ha affatto. Sul numero del sentiero la regola resta.

- **Mirror su un JSON sull'`EcTrack`** (come diceva ancora la description del ticket) → spostato
  sul codice del Catasto Sentieri: la description era superata, l'analisi l'ha corretta in fase di
  brainstorming (oc:8539).
- **Lista di anomalie separata per il registro** → estesa la lista di anomalie già esistente del
  catasto, con provenienza (`source`) distinta invece di una Resource Nova a parte (oc:8539).
- **Tabella del mirror senza foreign key verso il codice** → aggiunta la FK con `cascadeOnDelete`,
  per farla ricostruire dal reset notturno invece che da un passo di pulizia dedicato (oc:8539).
- **Nuova migration per la colonna `source` delle anomalie** → richiesta del dev: fusa nello stub
  di creazione della tabella anomalie del package, invece di un secondo stub pubblicato in coda
  (oc:8539).
