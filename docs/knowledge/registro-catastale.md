# Registro catastale

Mirror in piattaforma del foglio Google che Forestas condivide con il CAI: un registro storico dei
codici del Catasto Sentieri, tenuto a mano, che il cliente vuole smettere di aggiornare ma non
perdere.

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

Le righe con link vuoto e nessun candidato nel ripiego **non** sono anomalie: sono numeri
prenotati, senza scheda su Sardegna Sentieri, per accordo esplicito col cliente (oc:8539).

**Interfaccia Nova.** `App\Nova\TrailRegistryCode` aggiunge alla scheda del codice una Tab
«Registro» (`RegistroTabRenderer`) con le colonne del foglio in righe; se la riga del codice è
finita in anomalia, la Tab mostra un avviso con link all'anomalia invece della tabella.
L'anomalia si cerca per traccia del codice, per `context.code` o fra i `context.candidates`, tutti
nella forma di `TrailRegistryCode::code`. Il link dal dettaglio dell'anomalia è cliccabile solo se
schema (`http`/`https`) e host (`sardegnasentieri.it` o un suo sottodominio) sono davvero quelli
di Sardegna Sentieri: la cella è testo libero del foglio.
`App\Nova\TrailRegistryAnomaly` distingue le due provenienze con un filtro
(`App\Nova\Filters\TrailAnomalySourceFilter`) e adatta titolo e testo secondo la provenienza
(oc:8539).

## Perché così

- **Drupal resta l'unica fonte di verità.** Il foglio è compilato a mano e può sbagliare; il
  mirror lo conserva e segnala le discrepanze, non le corregge (oc:8539).
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
