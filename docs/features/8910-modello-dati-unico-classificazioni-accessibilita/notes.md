> Ticket: oc:8910

# Notes — Modello dati unico per classificazioni e accessibilità

## Deviazioni dal piano

Nessuna finora.

## Bug trovati

Nessuno finora.

## Decisioni

- **I punteggi di inclusività si perdono a ogni reset notturno, ed è accettato.** I tre interi e i
  due testi liberi sono inseriti a mano in Nova e non derivano da nessuna sorgente: il
  `TRUNCATE TABLE ec_tracks RESTART IDENTITY CASCADE` del reset
  (`app/Console/Commands/ImportSardegnaSentieriCommand.php:810`), che su UAT gira ogni mattina
  alle 06:00 con `SARDEGNASENTIERI_DAILY_RESET=true`, li azzera senza che nulla li possa
  ricalcolare. Il dev ha deciso di non proteggerli in questo ticket: non siamo in produzione, il
  cliente sa che il dato si perde a ogni reset e per ora gli serve solo provare che il
  funzionamento ci sia. Le due strade scartate — rileggere e riscrivere i valori intorno al
  `TRUNCATE`, oppure tenerli in una tabella a parte legata al codice catastale — restano
  disponibili quando il reset notturno verrà spento.

- **Un solo ticket per i due repo.** La proposta di separare l'infrastruttura del package dai dati
  di Forestas è stata respinta: la competenza la separano già i repo, e senza i casi di Forestas
  il modello del package non si può provare su dati veri.

- **Il vincolo «un solo grado di accessibilità» sta nell'applicazione, non nel database.** Era
  stato proposto un indice unique parziale sul pivot. È stato tolto: il predicato di un indice
  PostgreSQL non può leggere la tabella dei vocabolari (`cannot use subquery in index predicate`,
  provato), e l'unico modo per esprimerlo sarebbe copiare un flag su ogni riga della relazione e
  tenerlo allineato a mano. «Un grado solo per sentiero» è una regola di business — il cliente
  l'aveva già scritta al contrario e l'ha cambiata in call il 29/10 — non un invariante dello
  schema. Resta una colonna `single_choice` sulla **vocabolario**, letta da Nova per mostrare la
  select giusta e dall'import per scrivere una sola relazione.

- **Le durate non diventano colonne del pivot.** `duration_forward` e `duration_backward` sono
  l'unico esempio esistente di campo specifico sulla relazione e non sono usate: su Forestas
  `taxonomy_activityables` ha 1805 righe e zero durate valorizzate, e il codice che le leggeva dal
  pivot è commentato (`wm-package/src/Models/EcTrack.php:390`). Al loro posto un `properties`
  jsonb sulla relazione, interpretato secondo il `type` del vocabolario. L'obiezione «in jsonb non si
  filtra» non si applica: questi dati si leggono da Elasticsearch, non da SQL.

- **Nel package non si rimuove nulla.** Le diciotto chiavi `access_*` e `properties->difficulty`
  restano: sono mappate dall'import GeoHub (`config/wm-geohub-import.php:388`, `480-493`) e
  vivono sugli shard che importano da lì; toglierle dal modello le farebbe scartare in silenzio
  dal mass assignment. Su Forestas si tolgono solo i due tab Nova, dove quei campi sono vuoti su
  780 tracce e 976 POI. Far convergere la vecchia accessibilità nel modello nuovo è un ticket a
  parte.

- **L'inclusività è di Forestas, non del package.** I tre interi dell'Allegato H, i due testi, il
  punteggio e le soglie nascono dal regolamento della Regione Sardegna: stanno nel repo dello
  shard. Al package resta il modello generale.

- **Ogni colonna che uno shard aggiunge a una tabella del package porta il nome dello shard come
  prefisso** (`forestas_registry_code`, `forestas_survey_date`, `forestas_geographic_area`).
  Senza, il giorno che il package aggiunge una colonna con lo stesso nome il suo `ALTER TABLE`
  fallisce al deploy proprio sugli shard che se l'erano già creata. Da proporre come regola nel
  `CLAUDE.md`.

- **Il grado e le disabilità si danno solo ai sentieri, non ai POI.** Lo dice la call del 29/10:
  Saba, «la scala di difficoltà è unica per il sentiero», «associata al sentiero», «di ogni
  sentiero diciamo anche se è accessibile e per una, due o tutte e tre le disabilità». I punti di
  interesse compaiono solo come oggetto del vecchio modello ITACA.

- **I nomi di questo lavoro portano `inclusivity`** e lasciano libero il namespace `access*`:
  il package rifarà un'accessibilità generica per conto suo. È anche il nome corretto secondo la
  call — Saba: «l'errore qui è la maledetta parola accessibilità, che è stata usata troppo».

- **Stima saltata su richiesta del dev.** `wm-estimate` aveva prodotto 9,5h stimate più 1,68h di
  pianificazione misurata, ma su una lettura parziale degli overview (output troncato) e senza
  `notes.md`, tanto che includeva ancora il flag sulla relazione poi eliminato. Il dev ha deciso di non
  scrivere `estimated_hours` su Orchestrator.


## Decisioni prese durante l'esecuzione

- **Le righe di `field_frubilita` che puntano a una radice si ignorano**, non diventano attività.
  Le attività di un sentiero vengono da `field_tipologia_sentiero`
  (`SardegnaSentieriDatabaseSource.php:113`), un campo suo che l'import legge già: dedurle dalla
  difficoltà ne attribuirebbe di inesistenti. Sono 67 righe. Cade con questa anche la creazione
  dell'attività «mobilità dolce», che resta una questione aperta con il cliente — se vada aggiunta
  al format consegnato al SUS — e non si decide inventando un identifier. Le sue **difficoltà** si
  importano regolarmente, sono circa 55.

- **Le due vocabolari dell'accessibilità si sostituiscono solo se Drupal ha un dato per quel
  sentiero.** Erano trattate come le scale di difficoltà, cioè riscritte a ogni import: ma in
  tutta la copia Drupal ci sono **due righe su 614 sentieri**, e grado e categorie li compila un
  operatore in Nova. Così com'era, quel lavoro spariva al primo reimport del nodo — non serviva
  nemmeno il reset notturno.

- **I due testi liberi vanno protetti dall'`array_merge` dell'import**, che riscrive
  `properties->forestas` per intero. Stesso errore del punto precedente in un posto diverso: ogni
  volta che un dato manuale vive nello stesso contenitore di uno importato, la sostituzione se lo
  porta via.

- **`--fresh` tolto dal reset automatico**, resta disponibile a mano. Azzerando `taxonomy_terms`
  la chiave esterna cancella a cascata **tutti** le relazioni, anche quelli di contenuti che l'import
  non ricrea, e il filtro nel reset non lo impedisce perché la cascata agisce prima. Il motivo per
  cui era stato chiesto — vedere applicate le righe aggiunte all'elenco — vale comunque, perché il
  comando in modalità normale aggiorna nome, descrizione e icona.

- **Niente colonna `forestas_geographic_area`: 13 sentieri su 614 hanno più di una zona**, e in
  Drupal quel campo è una lista. Una colonna scalare li troncherebbe in silenzio. La zona resta in
  `properties->forestas.zona_geografica`. Le colonne sono quindi cinque, non sei.

- **L'elenco dei vocabolari sulla scheda del sentiero si legge dal database**, non da un array nel
  codice: altrimenti un vocabolario creata da Nova non comparirebbe finché qualcuno non la aggiunge
  lì, che è l'opposto della promessa del modello. Costa una query su una tabella di undici righe;
  niente cache, perché andrebbe invalidata a ogni modifica di un vocabolario e costerebbe più della
  query che evita. Le due vocabolari dell'inclusività restano esplicite, perché attorno hanno i tre
  interi e i due testi.

- **La mappa dei nove tid di accessibilità è stata verificata sulla copia Drupal**, uno per uno:
  4897/4900/4893 motorie, 4896/4892/4898 sensoriali, 4891/4901/4899 cognitiva, ciascuno con il suo
  grado. Era il punto in cui un abbinamento sbagliato non si sarebbe visto.

- **`withoutSyncingToSearch()` va chiamato sulla classe del package, non su quella dello shard.**
  L'observer del pivot risolve l'alias della morph map e ottiene `Wm\WmPackage\Models\EcTrack`,
  mentre Scout confronta la classe esatta: silenziare `App\Models\EcTrack` non spegne niente. Nel
  codice restano entrambe le chiamate, ma quella che conta è la prima.

- **Le avvertenze entrano in questo ticket, non in uno nuovo.** Deciso dal dev dopo l'import sui
  dati veri. `taxonomy_warnings` e `taxonomy_warningables` sono solo di Forestas — nel package
  non esistono — quindi la convergenza non tocca nessun altro shard, e le 13 avvertenze con i
  loro 818 relazioni vengono tutte da Drupal: non c'è lavoro di un operatore da salvare e i dati li
  riscrive l'import, senza migration di copia.

- **La vista Nova per vocabolario nasce nel package, non in Forestas.** Serve a dare a un vocabolario
  una voce di menu propria invece di mandare l'utente nell'elenco unico dei termini con un
  filtro. Scartata la strada povera — una voce di menu che apre l'elenco con il filtro già
  impostato — perché l'URL porta il filtro codificato in base64, si rompe al primo cambio e non
  dà né un titolo proprio né la creazione preimpostata dentro il vocabolario.

- **Le relazioni delle avvertenze passano da 818 a 794, ed è il numero giusto.** La vecchia tabella
  ne conteneva una ventina di orfani: `taxonomy_warningables` non veniva svuotata dal reset
  dell'import, e dopo `RESTART IDENTITY` quelle righe restavano attaccate a sentieri con lo
  stesso id. Il modello nuovo riparte dalla sorgente: 794 relazioni su 219 sentieri, esattamente i
  riferimenti distinti di `field_avvertenze_e_pericoli` in Drupal, zero orfani.

## Cose da chiedere a Forestas

- **Le soglie delle classi A3, A4, A5 e A6.** La call del 29/10 ha fissato solo A1 (da 80 compreso
  in su) e A2 (57-79). Le altre quattro, oggi in `config/forestas.php`, sono **provvisorie e
  inventate**: stanno in configurazione proprio per essere cambiate senza toccare il codice, ma
  finché non arrivano quelle vere un sentiero in classe A4 è in una classe che ci siamo dati noi.
- **I valori di «Tipo percorso» e «Tematismi»**: le due vocabolari nascono vuote. Per i tematismi la
  call dice che la lista la propone Webmapp e la approva Forestas.
- **Un'icona per «Sensoriale»**: nel font ci sono solo quelle separate per vista e udito, perché la
  categoria unica è una richiesta nuova.
- **Se «mobilità dolce» debba esistere come attività**, oltre che come scala di difficoltà.

## Follow-up

- Quando il reset notturno sparirà (dismissione di Drupal), decidere dove vivono i dati di
  accatastamento compilati a mano, che Drupal non avrà mai.
- **`RESTART IDENTITY` nel reset**: non è il pivot che sopravvive a creare il danno, è il riuso
  degli id dei sentieri. Finché c'è, ogni tabella polimorfa nuova va aggiunta a mano alla lista
  delle cancellazioni, e gli id delle tracce cambiano a ogni reset. Fuori da questo ticket.
- **Icona per «Sensoriale»**: nel font ci sono solo `txn-visual-disability` e
  `txn-hearing-disability`. La categoria unica nasce ora e un'icona sua non esiste.
- **La gerarchia dei termini è a due livelli per accordo, non per vincolo**: niente impedisce da
  Nova di creare un termine figlio di un termine, e in quel caso `parent_id` sulla relazione non è più
  il vocabolario. Si chiude con un controllo in validazione.

## 3 novembre, pomeriggio — rinomina, scheda sentiero, Layer

Da qui in avanti il concetto si chiama **tassonomia**. Sopra si legge «famiglia» e «vocabolario»:
sono i nomi provvisori che ha avuto nella stessa giornata, lasciati lì perché queste note sono un
diario. «Vocabolario» resta solo dove indica quello di Drupal.

### Decisioni

- **Tematismi e «Itinerari e cammini» sono stati ritirati.** Il primo nasceva vuoto e la lista non
  è mai arrivata; il secondo diceva la stessa cosa di «Tipo percorso», detta peggio. Il comando
  ora ha `RETIRED` per toglierli e `RENAMED` per portare avanti gli identifier cambiati in corsa,
  così un ambiente già allineato non resta con le righe vecchie. L'import è stato corretto nello
  stesso passaggio: `field_tipologia_itinerario` scrive su «Tipo percorso».

- **Le tre attività che reggono i layer si nascondono, non si cancellano.**
  `sardegnasentieri:type:sentiero`, `:itinerario` e `:cammino` sono righe di
  `taxonomy_activities` e non sono attività, ma i due layer del sito le usano via
  `whereHas('taxonomyActivities')`. Cancellarle richiede prima di spostare i layer su «Tipo
  percorso» e di correggere `syncTrackActivities()` nell'import: non è in questo ticket.
  Nascoste in Nova con `legacyTaxonomies()`.

- **«Accessibile» non è un campo.** Nessun interruttore sì/no: un sentiero è accessibile quando
  almeno un campo di inclusività è valorizzato. Dalle call con il cliente, confermato due volte.

- **Le tab della scheda del sentiero sono tre** — Generale, Attività, Inclusività — e «Attività»
  viene subito dopo «Generale» perché le difficoltà dipendono da essa: chi compila sceglie prima
  l'attività e poi vede comparire le scale che le competono. «Generale» è la tab aperta per prima.
  «Proprietà» è stata spostata in fondo alla pagina.

- **L'indice Elasticsearch vecchio è stato cancellato**, su richiesta del dev. L'alias `ec_tracks`
  puntava a due indici e sul più recente c'era un filtro `must_not _index` lasciato da uno
  `scout:import` interrotto: si scriveva sul nuovo e si leggeva da uno fermo al 6 ottobre. Rifatto
  l'alias senza filtro, staccato e poi eliminato `ec_tracks_1791277311`.

### Errori miei, dichiarati perché hanno prodotto diagnosi sbagliate

- **Escape sbagliati in `tinker`** (`"App\\\\Models\\\\EcTrack"`) mi hanno fatto riferire «pivot a
  zero» e «0 tracce» quando erano 609. Chi legge una verifica fatta da riga di comando controlli
  prima che la classe nel filtro sia quella giusta.
- **Id inventati** (13/61/62 invece di 51 e 52-64) riportati come se fossero dati letti.
- **Un'ipotesi presentata come fatto**: «le attività vengono da un import GeoHub». Verificato dopo
  l'obiezione del dev: le sette attività sono state inserite in blocco il 22/09/2026 da qualcosa
  che nel repository non c'è.
- **Pint lanciato su directory intere** due volte, riformattando 19 + 8 file estranei al lavoro,
  poi ripristinati. Il dev: «tu correggi dove sbagli tu o dove devi fare refactoring, il resto si
  vede».

### Cose da chiedere o da decidere

- **L'icona di «Difficoltà mobilità dolce»** è ancora `txn-bike` ed è sbagliata: quella scala
  riguarda chi cammina piano, non le biciclette.
- **L'automatismo «percorsi accessibili»** — accendere l'attività quando c'è almeno una disabilità
  accolta — è confermato nelle due call come «nice to have» e non è stato fatto.
- **57 tracce hanno solo il termine Drupal `trekking`**, non `hiking` (46 di esse con la
  difficoltà già valorizzata): su quelle i campi che dipendono da `hiking` non compaiono.
  `trekking` (tid 4831) e `hiking` (tid 4825) sono due termini Drupal distinti — 452 tracce solo
  `hiking`, 97 entrambi. Va deciso se accorparli.
- **«Percorsi accessibili» come attività** va tolta a regime, ma si tiene per lo storico.

## 3 novembre, sera — «dopo l'import notturno non si fa niente»

Il collaudo dell'import ha fatto emergere un difetto che non era nella feature ma che la feature
avrebbe portato in collaudo. Richiesta del dev, testuale: «il punto è non fare nulla dopo l'import
giornaliero», inteso come ciò che parte da solo alle 06:00 su UAT
(`routes/console.php:41`, `sardegnasentieri:import --reset`).

### Il difetto

L'import con `--reset` chiamava `sardegnasentieri:configure-taxonomy-terms --fresh`, e `--fresh` fa
`DB::table('taxonomy_terms')->delete()`. Il pivot `taxonomy_termables` ha `cascadeOnDelete` sul
termine: quel delete portava via **anche le relazioni dei layer**, che con l'import non c'entrano
niente. I termini rinascevano con id nuovi e i layer restavano senza filtri — mappe vuote e lista
non filtrata — fino a un `configure-layers` lanciato a mano. Di notte non c'è nessuno a lanciarlo.

Il passo manuale era già scritto nelle trappole del repo («e di nuovo dopo il reset»), quindi il
difetto era noto come procedura, non come bug. Con il modello unico diventava un bug: prima erano
le attività a tenere le relazioni, e le attività il reset non le tocca.

È anche la spiegazione vera dei due layer trovati vuoti in locale durante il collaudo. Li avevo
attribuiti a una prova in Nova prima della correzione del campo nascosto: il percorso notturno li
svuota da solo a ogni giro.

### Decisioni

- **Via `--fresh` dall'import, non solo con `--reset`: proprio via.** L'allineamento senza opzioni
  fa già tutto — `align()` cerca per `identifier`, `renameIdentifiers()` e `removeRetired()` tolgono
  le righe fuori elenco. Svuotare non aggiungeva pulizia, aggiungeva solo la perdita degli id.
  L'opzione resta sul comando per un uso a mano.
- **Le relazioni delle tracce li cancella `resetData()`, per morph type.** Quelli sì devono sparire col
  troncamento, e sparivano già da lì: `--fresh` non serviva nemmeno a quello.
- **`configure-layers` lo chiama l'import con `--reset`**, prima del batch, con `--no-recalc`: i
  relazioni dei layer non dipendono dalle tracce, e il ricalcolo lo fa già la catena di fine batch.
  Non si chiama nell'import orario, dove niente è stato azzerato e un `sync()` ogni ora
  sovrascriverebbe le modifiche fatte in Nova.
- **`configure-layers` passa dalle attività ai termini.** `LAYER_ACTIVITIES` → `LAYER_TERMS`:
  Sentieri/Itinerari/Cammini sui tre termini di `route-type`, e «Sentieri Accessibili» su tutta la
  scala `inclusivity-difficulty` (AT, AE, AEE), perché dentro una tassonomia il filtro è in OR e
  «accessibile» significa «ha una difficoltà di accessibilità, qualunque sia».
- **Un layer assente si salta, non fa fallire gli altri.** In locale ce ne sono due dei quattro, e
  il comando vecchio usciva in errore senza scrivere niente. Si esce in errore solo se mancano i
  termini, o se non si è potuto scrivere nemmeno una relazione.
- **Le relazioni si scrivono con gli eventi accesi**, al contrario di come si faceva con le attività:
  è il `saving` di `TaxonomyTermable` a copiare la tassonomia su `parent_id`. L'observer che
  reindicizza non costa nulla su un Layer, che non è `Searchable` ed esce subito.

### Due difetti del package corretti nello stesso giro

- **`Layer::taxonomyTerms()` sovrascriveva la relazione del trait con una versione nuda**, senza
  `using(TaxonomyTermable::class)`: le righe scritte da lì non passavano dal `saving` e restavano
  con `parent_id` nullo. L'override è stato tolto, vale quella di `TaxonomyAbleModel`.
- **`LayerableObserver::handleRelatedFeaturesUpdate()` passava `null`** a
  `updateLayerIdsPropertyOnLayeredFeature()` quando il contenuto collegato non esiste più: un
  `SyncAutoLayerAfterTrackTaxonomyChangeJob` morto con `TypeError` durante il collaudo. Aggiunta la
  guardia su `model` e `layer`.

### Verifiche

- Il test nuovo in `ImportResetGuardsTest` («il reset non porta via le relazioni dei layer») è stato
  provato **anche al contrario**: rimettendo `--fresh` fallisce, come deve.
- `ConfigureSardegnaSentieriLayersCommandTest` rifatto sui termini: 10 test, compresi il layer
  accessibili sull'intera scala, `parent_id` scritto sulla relazione, e il layer assente che non fa
  fallire gli altri.
