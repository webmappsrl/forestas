> Ticket: oc:8932

# Import nello shard dei dati di Drupal che mancano al nuovo sito

## Cosa cambia

L'import dalla copia del database Drupal porta nello shard i dati che il sito di Sardegna Sentieri
mostra oggi e che lo shard non ha, o ha in una forma che il nuovo sito WordPress non può usare: il
`name` delle tipologie, i collegamenti fra contenuti, i file (oggi indirizzi del sito Drupal, che
verrà spento), i dati degli enti.

Due cose cambiano anche senza venire da Drupal: i sentieri correlati fra loro non si importano più
ma si calcolano dalla geometria, e il limite di dimensione dei file passa da 20 a 80 MB.

Il lavoro sta quasi tutto nel repo `forestas`. Nel `wm-package` si toccano tre punti, indicati in
«Moduli toccati»: tutti e tre nascono dall'arrivo di file che non sono immagini in una media
library che finora ne conteneva solo.

## Perché

Il nuovo sito WordPress legge sentieri, itinerari, punti di interesse ed enti dallo shard e li deve
mostrare come li mostra oggi Drupal. Drupal verrà spento: i file linkati al suo dominio smetteranno
di rispondere, e ciò che non è stato portato nello shard non sarà più recuperabile da nessuna parte.

Richiesta di Giuseppe Bonfanti allo scrum del 04/11/2026: «puoi fare all'interno di questo tag un
ticket dove mi dici e WordPress ha bisogno di questi dati». Il 06/11 il ticket è stato assegnato,
con l'indicazione di partire dalle tassonomie «che ci servono anche per il frontend».

## Requisiti

### Tipologie

- [ ] Il `name` dei tipi di punto di interesse e delle attività porta **il nome del termine Drupal**
      al posto dell'identifier (oggi `name` vale `crossroads`, `monumental-tree`, `tlc`). Restano nei modelli
      che li ospitano già, `TaxonomyPoiType` e `TaxonomyActivity`: `taxonomy_terms` del modello
      unico si usa solo per tassonomie nuove.
- [ ] Le 28 opzioni restano 28: l'accorpamento di più termini Drupal in un tipo solo resta com'è, e
      il `name` è quello del termine già registrato in `properties.source_id`. Riguarda
      `crossroads` (tre termini, 234 punti di interesse) e `disabled-access` (tre termini, 8).
- [ ] Dove Drupal ha il nome inglese lo si porta; dove manca — 12 tipologie su 21 e tutti gli 11
      servizi — non si fa nulla: il ripiego sull'italiano è già il comportamento dei campi tradotti.

### Collegamenti fra contenuti

- [ ] I collegamenti fra un punto di interesse e un percorso si importano **tutti e in un elenco
      solo**, reciproco, nel pivot `ec_poi_ec_track`: `field_poi_correlati` del percorso (1246
      coppie), `field_sentieri_collegati` e `field_itinerari_correlati` del punto di interesse (574)
      e `field_punti_di_interesse` degli itinerari, che oggi non viene letto affatto.
- [ ] Si scartano le coppie in cui percorso e punto di interesse distano **più di 10 km**: sono 29
      su 1643, fino a un caso a 174 km, e sono errori della redazione.
- [ ] I collegamenti fra punti di interesse si completano: oggi ne arrivano 240 su 637, perché un
      riferimento a un contenuto non ancora importato viene scartato in silenzio. Anche questi sono
      reciproci, quindi diventano 1143 righe.
- [ ] **I collegamenti fra contenuti sono uno specchio di Drupal**: in Nova il campo è in sola
      lettura, su percorsi e punti di interesse. L'import li riscrive a ogni giro, quindi un
      collegamento aggiunto a mano sparirebbe senza avviso — e un campo che permette un'azione
      destinata a essere annullata è peggio di uno che non la permette. Vale la stessa logica già
      applicata agli enti.
- [ ] Nessun collegamento va perso perché il contenuto citato viene importato dopo: i riferimenti si
      risolvono quando tutti i contenuti sono stati scritti.
- [ ] I **sentieri correlati fra loro non si importano**: si calcolano dalla geometria, collegando i
      percorsi entro un raggio configurabile (default 50 metri) e salvando il risultato. Il raggio
      sta in un `config/` di `forestas`, perché è una scelta di questo progetto.

### File

- [ ] Il limite di dimensione sale a **80 MB per i soli file che l'import scarica**: a 80 MB non
      resta fuori nessuno dei 4290 file da importare, il più grande è 78,2 MB. Il limite generale
      dello shard **resta 20 MB**: il `wm-package` riversa `wm-media-library` dentro la
      configurazione dei media (`WmPackageServiceProvider.php:589`), quindi alzarlo in un file di
      progetto lo alzerebbe anche per le foto inviate dall'app e per gli upload in Nova.
- [ ] Gli **allegati** (163 file, 903 MB) vengono copiati nello shard nella media library, in una
      collection dedicata, con il titolo che Drupal mostra. Oggi sono indirizzi del sito Drupal in
      `properties.forestas.allegati` su 147 percorsi, e sui punti di interesse non ci sono affatto,
      perché l'import li scrive vuoti.
- [ ] I **video caricati su Drupal** (4 file, da 1,5 a 78,2 MB) vengono copiati nella media library
      come gli allegati. I video di YouTube restano dove sono, in `properties.forestas.video`.
- [ ] Di ogni foto si salva anche la **didascalia**, che oggi va persa: il nome del media è il nome
      del file trasformato. Autore e credits l'import li salva già.
- [ ] Quando un file su Drupal è collegato solo alla traduzione inglese del contenuto, lo si prende
      da lì: oggi l'import legge la sola lingua originale e perde 37 immagini su 30 percorsi e gli
      allegati di 3 sentieri.
- [ ] Le 5 immagini che non arrivano senza una causa trovata (nodi 3956, 3966, 4601, 5066, 5441)
      vanno verificate.
- [ ] In Nova i file si vedono e si gestiscono dalla pagina del contenuto, con **campi distinti per
      tipo** — «Immagini», «Allegati», «Video» — in lettura, aggiunta ed eliminazione, nel tab
      «Forestas» insieme agli altri campi custom. Oggi nessuna Resource ha un campo media: le foto
      si trovano solo dalla Resource `Media` filtrando per modello. Immagine principale e galleria
      restano nello stesso campo, perché condividono la collection `default` e si distinguono per
      ordine. Spariscono invece i campi che mostrano dati superati: gli allegati e i video come
      indirizzi a Drupal, il GPX e l'id grezzo della zona geografica.
- [ ] Un file già caricato si **riusa senza ricaricarlo**, su tutti e tre i campi: si dichiarano
      con `->enableExistingMedia()` e si accende `enable-existing-media` in un
      `config/nova-media-library.php` di forestas, oggi assente (il default del pacchetto è
      `false`). Serve perché su Drupal lo stesso file è riferito da più contenuti, e il caso più
      frequente sono le **foto**, non i documenti: 813 immagini di galleria riusate e 221 immagini
      principali, contro 26 allegati e 5 video. La media library tiene una copia per contenuto,
      quindi senza questo la redazione dovrebbe ritrovare e ricaricare ogni volta lo stesso file.

### Collegamenti esterni

- [ ] I link esterni che Drupal mostra sotto «Collegamenti» si importano in `related_url`: 47 punti
      di interesse e 75 itinerari. Il formato di destinazione è `label => url`, e il
      `wm-package` ha già il trait `NormalizesRelatedUrl` che lo normalizza.
- [ ] Il campo `related_url` viene aggiunto a `EcTrack` nel `wm-package`, dove oggi esiste solo su
      `EcPoi`, così un link esterno si chiama e si legge allo stesso modo sui due tipi di contenuto.
- [ ] I collegamenti dei punti di interesse si spostano da `properties.forestas.collegamenti`, dove
      sono un array piatto che alterna url e titolo. Il campo Nova che li mostra oggi
      (`app/Nova/EcPoi.php:83`, `KeyValue::make('Collegamenti', 'properties->forestas->collegamenti')`)
      va fatto puntare alla destinazione nuova, altrimenti resta vuoto.

### Enti

- [ ] L'immagine principale si legge dal campo media: oggi l'import prende solo quello vecchio (44
      enti), mentre 50 enti hanno soltanto il nuovo.
- [ ] Si importano la galleria fotografica (31 enti) e i collegamenti esterni (10).
- [ ] **Titolo della pagina web, data di creazione e slug** diventano colonne di `entes`: lo slug
      serve a raggiungere un ente dall'indirizzo `/enti/<slug>` e si ricava dall'ultima parte
      dell'alias Drupal.

### Punti di interesse

- [ ] Si importa la **data di creazione**, oggi assente su tutti i 976 punti di interesse mentre sui
      percorsi c'è.
- [ ] Si importano gli allegati (12 punti di interesse).

### Al rilascio

- [ ] **Dopo il deploy serve un import forzato**, altrimenti i dati nuovi non arrivano sui contenuti
      già importati: l'import incrementale salta i nodi il cui `changed` non è cambiato, e nessuno
      di questi dati cambia il `changed` su Drupal. Vale per didascalie, `related_url`, allegati,
      date di creazione e nomi delle tipologie. Il comando ha `--force` («reimport all ignoring
      updated_at»), implicato anche da `--reset`. Su dev e UAT lo fa da sé il reset notturno, la
      notte dopo il deploy; dove il reset non c'è va lanciato a mano.
- [ ] **I due comandi dello stato di validazione vanno lanciati a mano, in quest'ordine**, su ogni
      database che ha già dei dati:
      `php artisan sardegnasentieri:configure-taxonomy-terms` (senza `--fresh`, che svuoterebbe
      `taxonomy_terms` portandosi via i filtri dei layer) e poi
      `php artisan sardegnasentieri:move-validation-status` (ha `--dry-run`), che sposta i 742
      stati dalla colonna ai termini e poi toglie la colonna. Senza, i percorsi mostrano lo stato
      vuoto in Nova e il semaforo del nuovo sito resta spento: un reimport **non** li ricostruisce,
      perché l'import non riscrive lo stato se Drupal non lo dichiara. Dopo lo spostamento serve un
      reindex: il comando non sveglia Elasticsearch. La riga della migration cancellata resta nella
      tabella `migrations` dei database esistenti e va tolta a mano, altrimenti un
      `migrate:rollback` fallisce con «migration not found».
- [ ] Il `wm-package` si merge **prima** dello shard, e gli shard che bumpano il puntatore
      ereditano tutte e quattro le modifiche. Dove la copertina delle tracce cambia serve un
      reindex.

### Stato di validazione come termine

Aggiunto in corso d'opera su richiesta del dev, e fuori dal perimetro originario: non è un dato
mancante da portare, ma un dato già importato che cambia forma.

- [ ] Lo **stato di validazione** dei percorsi smette di essere una colonna con un enum PHP e
      diventa una famiglia del modello unico delle tassonomie, con i suoi sette termini e i nomi
      italiani che oggi stanno nell'enum. La mappatura dagli id dei termini Drupal resta il
      contratto con la sorgente.
- [ ] I 742 percorsi che hanno già uno stato non devono perderlo.
- [ ] Il cambiamento è circoscritto allo shard: la colonna è usata in cinque file e **nessuno nel
      `wm-package`**, quindi nessun altro progetto ne risente. Cambia però il modo in cui il nuovo
      sito leggerà il dato, che gli serve per il semaforo dello stato della rete: il ticket delle
      API deve saperlo.

### Quello che si vede in Nova

Aggiunto in corso d'opera, collaudando la scheda con il dev: un dato importato che nessun campo
mostra è come non averlo portato, e tre difetti sono emersi solo guardando le pagine vere.

- [ ] La **didascalia delle foto** si legge e si corregge dal campo Immagini: il dato arriva nelle
      custom properties del media (5006 foto), e senza un campo resta invisibile. Allegati e video
      non ne hanno nessuna.
- [ ] Il punto di interesse mostra la **data di creazione in Drupal**, come già il percorso.
- [ ] I **collegamenti esterni dell'ente** si presentano come tabella chiave-valore, la stessa
      forma usata su percorsi e punti di interesse, invece del JSON grezzo.
- [ ] Il percorso mostra gli **enti collegati con il loro ruolo**: è la sezione «Contatti a Guide,
      Gestori ed Enti» di Drupal, e il dato c'era già nella pivot `enteables` (1314 collegamenti
      su 558 percorsi) ma si vedeva solo aprendo l'ente. In sola lettura come le altre relazioni
      importate.
- [ ] Le **foto compaiono una volta sola**: il campo immagini del `wm-package` punta alla stessa
      collection di quello di forestas, e in pagina le stesse foto erano doppie. Si tiene quello di
      forestas, che permette di scegliere un file già caricato — in Drupal la stessa immagine è
      richiamata da più contenuti (813 nelle gallerie, 221 fra le principali), mentre qui ogni
      contenuto ha la sua copia: 5280 immagini importate per 3166 file distinti.
- [ ] I **Tipi POI non compaiono più sui percorsi**: la relazione sta in un trait che usano tutti i
      modelli EC, quindi il controllo del `wm-package` la riconosceva anche sulle tracce.
- [ ] Lo **stato di validazione** apre il tab «Generale» delle classificazioni, e la scheda ne
      mostra l'etichetta: `displayUsingLabels()` di Nova legge l'attributo del modello e ignora il
      `resolveUsing()` del campo, quindi senza un attributo vero il valore restava un trattino.

### Verifica

- [ ] Test automatici sui percorsi nuovi dell'import.
- [ ] I casi citati nel ticket si controllano in Nova: il sentiero B 401, l'itinerario «Lago di
      Baratz – Le Prigionette», il punto di interesse «Nuraghe Serbissi», l'ente «GAL BMG».
- [ ] Un confronto fra i conteggi della copia Drupal e quelli dello shard, per ogni dato portato.

## Rischi

**Il pivot `ec_poi_ec_track` scritto da due parti.** I collegamenti arrivano sia dal percorso sia
dal punto di interesse. Oggi l'import scrive con `$ecTrack->ecPois()->sync($poiIds)`
(`SardegnaSentieriImportService.php:801`): due `sync()` indipendenti si cancellerebbero a vicenda,
e `syncWithoutDetaching()` non rimuoverebbe mai un collegamento sparito alla fonte. Mitigazione: i
collegamenti non si scrivono più nel job del singolo contenuto, ma nel passaggio finale che risolve
i riferimenti, con un `sync()` solo per contenuto.

**Il raggio di vicinanza interpretato in gradi.** Le geometrie sono PostGIS 4326: uno
`ST_DWithin(a.geometry, b.geometry, 50)` senza conversione misura 50 **gradi** e collega mezza
Sardegna. Mitigazione: il confronto si fa sempre con il cast a `geography`, come nelle misure fatte
in fase di analisi, e il piano lo riporta come vincolo. Vale anche per la soglia dei 10 km sui
collegamenti con i punti di interesse, che va misurata sulla geometria del percorso e non su un suo
punto rappresentativo, altrimenti su un itinerario lungo si scartano collegamenti buoni.

**La copertina delle tracce cambia per tutti gli shard.** `toSearchableArray()`
(`wm-package/src/Models/EcTrack.php:732`) sceglie la copertina con `getMedia('*')`, e in Spatie
l'asterisco **disattiva il filtro sulla collection** (`InteractsWithMedia::loadMedia()`, riga 651):
arrivano tutti i media del contenuto, ordinati per `order_column`, quindi con allegati e video nella
media library un PDF può diventare la copertina indicizzata. Si legge invece dalla collection
`default`, quella in cui il package scrive le immagini. Il `wm-package` è condiviso e il valore
indicizzato cambia: serve un reindex delle tracce dove la correzione arriva.

**La risoluzione dei riferimenti nel giro orario.** Il passaggio finale che collega i contenuti
funziona nel giro completo, dove tutti i nodi sono stati scritti. Nell'import incrementale vengono
importati solo i nodi con `changed` mosso, quindi il contenuto citato può non essere nel batch: la
risoluzione deve cercare nel database e non fra i contenuti di quel giro, e reggere il caso in cui
il contenuto citato non sia mai stato importato.

**Rischi esclusi dopo verifica.** Il riuso degli id dopo il reset notturno attacca una parte dei
media al contenuto sbagliato (misurati 2 casi su 60, più 224 media orfani), ma riguarda solo gli
ambienti con il reset acceso: l'import di produzione parte da un database vuoto e non riusa nessun
id. Gli slug degli enti sono unici (92 enti, 92 alias, 0 duplicati). Il calcolo per vicinanza non
produce troppi collegamenti: 1527 coppie su 761 percorsi, circa due per percorso.

## Domande aperte

Dalla prima stesura dell'overview (06/11) resta aperto un punto, che riguarda un altro ticket:

- **oc:8920 deve conservare le zone di Drupal.** Quando `syncTaxonomyWhere()` scrivera'
  `taxonomy_whereables`, deve sostituire solo le righe che calcola e conservare quelle con
  un'altra `source`, altrimenti cancella le zone che arrivano da Drupal. Va messo come vincolo
  su oc:8920.

Le altre due domande di quella stesura sono cadute con il perimetro: le zone geografiche
calcolate dal poligono sono fuori scope, e gli identifier Geohub non si abbandonano piu', perche'
questo lavoro cambia il `name` delle tipologie e lascia l'identifier com'e'.

## Out of scope

- **Le API che espongono questi dati a WordPress**: sono un ticket a parte. Va però registrato un
  fatto emerso qui: l'API del dettaglio di un percorso non restituisce oggi nessun dato tecnico —
  né `distance`, né `ascent`, né `manual_data` — quindi il nuovo sito non vedrebbe nemmeno la
  lunghezza dei 572 sentieri che ce l'hanno dal registro catastale.
- **Le zone geografiche**: si usano quelle che lo shard ha già.
- **Il file GPX**: non si copia da Drupal. La geometria dello shard basta a generarlo, ma la
  generazione è rotta per il tipo di geometria che usiamo — se ne occupa oc:8953, che copre anche il
  KML.
- **Quote, dislivelli, lunghezza, tempo e grafico altimetrico**: restano come sono. Il ricalcolo
  all'apertura del percorso in Nova esiste già, e il calcolo in blocco si lancerà una volta andati
  in produzione. Importarli da Drupal era già stato escluso in oc:8641, perché quei valori non sono
  misure.
- **I dati ricevuti dagli utenti** (segnalazioni e valutazioni dei moduli pubblici di Drupal):
  decisione del 29/10, in prima istanza su WordPress.
- **L'area riservata** (file privati di 123 sentieri e 1 itinerario).
- **I campi che Drupal non mostra**: tappe dell'itinerario, tag, data di rilievo, servizi degli
  enti, iframe della mappa, ordine di visualizzazione, filtro carte.
- **I punti aperti** elencati nel ticket, che aspettano una decisione del cliente o del team.

## Moduli toccati

### Repo `forestas`

| File | Cosa cambia |
|---|---|
| `app/Services/Import/Source/SardegnaSentieriDatabaseSource.php` | legge i campi Drupal oggi ignorati: `field_punti_di_interesse`, `field_sentieri_collegati` e `field_itinerari_correlati` dei punti di interesse, allegati dei punti di interesse, media degli enti, didascalie, `created`, alias; ripiego sulla traduzione inglese |
| `app/Services/Import/SardegnaSentieriImportService.php` | scrive i collegamenti unificati, il `name` delle tipologie, `related_url`, i campi nuovi degli enti; smette di scrivere i sentieri correlati |
| `app/Jobs/Import/` | risoluzione dei riferimenti dopo che tutti i contenuti sono stati importati; calcolo dei sentieri vicini |
| `app/Models/EcTrack.php` | relazione verso i percorsi vicini |
| `app/Models/Ente.php` | colonne nuove |
| `app/Nova/EcTrack.php`, `app/Nova/EcPoi.php`, `app/Nova/Ente.php` | campi media distinti per tipo (immagini, documenti, video); `app/Nova/EcPoi.php:83` punta alla destinazione nuova dei collegamenti; data di creazione sul punto di interesse; collegamenti dell'ente come tabella chiave-valore; enti collegati sul percorso; stato di validazione in testa al tab «Generale» |
| `app/Nova/Concerns/HasForestasMediaFields.php` | nuovo: i tre campi media, la didascalia come custom property, e il filtro che toglie il campo immagini del package |
| `app/Models/EcTrack.php` (`validation_status`) | l'identifier del termine come attributo del modello: senza, il `Select` di Nova mostra sempre un trattino |
| `database/migrations/` | tabella dei percorsi vicini; colonne nuove di `entes` |
| `config/wm-media-library.php` | nuovo: `max_file_size` a 80 MB |
| `config/` (file di progetto) | raggio di vicinanza dei percorsi |
| `app/Dto/` | i campi nuovi nei DTO dell'import |

### Submodule `wm-package`

| File | Cosa cambia |
|---|---|
| `src/Models/EcTrack.php`, `src/Nova/EcTrack.php` | `related_url`: `fillable`, cast e campo Nova, come già su `EcPoi` |
| `src/Models/EcTrack.php` (`toSearchableArray`) | l'immagine di copertina si legge dalla collection delle immagini e non da `getMedia('*')`, che con allegati e video nella media library prenderebbe un PDF |
| `config/wm-package.php`, `src/Nova/Concerns/BuildsTaxonomyFields.php` | le tassonomie storiche dichiarano su quali modelli valgono (`models`): `method_exists` non basta, perché le relazioni stanno tutte nel trait `TaxonomyAbleModel` |
| `src/Models/Abstracts/GeometryModel.php` (`registerMediaConversions`) | le miniature si registrano con `->performOnCollections('default')`: oggi non sono limitate a nessuna collection, e con i PDF e i video in libreria si accodano ritagli su file che immagini non sono |
