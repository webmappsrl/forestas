> Ticket: oc:8932

# Note — Import dei dati Drupal mancanti

## Da portare a Forestas: quattro immagini perse per sempre

Il ticket chiedeva di capire perché 5 immagini non arrivassero nello shard «senza causa trovata».
La causa c'è, ed è fuori dal nostro codice:

| Nodo Drupal | Cosa succede |
|---|---|
| 3956, 3966 | il file è registrato su Drupal ma l'indirizzo risponde **404** |
| 4601 «Panorama lungo il sentiero 410 - Armungia» | **404** |
| 5441 «Torre di Longonsardo» | **404** |
| 5066 `rocce_mamutorco.png` | esiste, ma pesa 23,3 MB: lo recupera il limite portato a 80 MB |

I quattro file con 404 **mancano dal disco di Drupal**: l'import non può prenderli perché non ci
sono. Quando Drupal verrà spento non saranno più recuperabili da nessuna parte.

È l'unico danno irreversibile emerso in questo lavoro, e va segnalato a chi gestisce Drupal finché
è acceso: o si recuperano quei file da un backup, o quelle quattro foto si perdono.

## Decisioni prese durante il lavoro

- **I collegamenti fra contenuti sono uno specchio di Drupal.** Il campo in Nova è in sola lettura
  su percorsi e punti di interesse: la redazione li vede ma non li modifica. Un collegamento
  aggiunto a mano verrebbe cancellato al primo import, e un'interfaccia che permette un'azione
  destinata a sparire è peggio di una che non la permette. Vale la stessa logica già applicata agli
  enti, che sono uno specchio dichiarato.
- **I collegamenti sono reciproci.** Su Drupal le liste sono compilate in un verso solo — fra punti
  di interesse e percorsi coincidono in 174 casi su 1646, fra punti di interesse in 131 su 637 — e
  quella asimmetria è un difetto di compilazione, non un dato.
- **I sentieri correlati si calcolano, i punti di interesse correlati si importano.** Sono stati
  misurati entrambi: fra i sentieri la distanza mediana è di 2 metri e metà delle coppie si tocca,
  quindi «collegati» descrive la geometria; fra i punti di interesse la mediana è di 1270 metri e
  nessuna soglia funziona — a 2 km il calcolo ne inventerebbe 2426 — quindi è una scelta editoriale
  che la geometria non può dedurre.
- **La didascalia delle foto si legge dal testo alternativo** (`field_media_image_alt`), non dal
  titolo: su questo Drupal è lì che la redazione l'ha scritta (3719 immagini su 3727 contro 1229).
  Chi farà le API lo usi sia come didascalia sia come testo alternativo.
- **I Tipi POI non compaiono piu' sulla scheda di un percorso.** La relazione
  `taxonomyPoiTypes()` sta nel trait `TaxonomyAbleModel`, che usano tutti i modelli EC: il
  controllo `method_exists` del package la riconosceva anche su una traccia, e il campo compariva
  li'. Ora la configurazione delle tassonomie storiche dichiara su quali contenuti vale ognuna
  (`models`), e senza quella chiave vale per tutti. Su un Layer il vincolo non si applica, perche'
  li' i campi sono filtri e un layer puo' raccogliere sia tracce sia punti di interesse.
- **Foto, allegati e video stanno tutti nel tab Forestas.** Il campo immagini del package punta
  alla stessa collection `default`, quindi le foto comparivano due volte: quello del package e'
  tolto dalla pagina (`HasForestasMediaFields::withoutPackageImageField()`) e si tiene quello di
  forestas, perche' permette di scegliere un file gia' caricato. Serve: in Drupal la stessa
  immagine e' richiamata da piu' contenuti (813 nelle gallerie, 221 fra le principali), e nello
  shard ogni contenuto ha la sua copia — 5280 immagini importate per 3166 file distinti, 1187
  scaricati piu' di una volta.
- **Il limite di 80 MB vale solo per i file che l'import scarica**, non per lo shard: il package
  riversa `wm-media-library` dentro la configurazione generale dei media, quindi alzarlo in un file
  di progetto lo alzerebbe anche per le foto inviate dall'app.

## Rinunce consapevoli

- **Il ricalcolo incrementale dei percorsi vicini non è stato fatto.** Oggi ogni import ricalcola
  tutte le coppie, in sedici blocchi da cinquanta tracce, anche quando nessuna geometria è
  cambiata. Il modo per farlo è descritto nel report del task: passare al job gli id delle tracce
  cambiate, cancellare le righe che li coinvolgono in entrambe le colonne e ricalcolare solo i loro
  vicini.
- **Il confronto con la sorgente non copre il nome delle tipologie.** Il comando verifica tutti gli
  altri dati portati, ma per i nomi dei tipi di punto di interesse servirebbe la mappatura interna
  del service.
- **I file condivisi fra più contenuti si duplicano.** Su Drupal lo stesso documento è riferito da
  più schede; nella media library ogni contenuto ha la sua copia, quindi i 163 allegati diventano
  248 righe. Per non far ricaricare lo stesso file alla redazione i campi permettono di sceglierne
  uno già presente, ma la copia sul disco resta.

## Cose trovate e non corrette, fuori perimetro

- Il `fillable` e il cast di `related_url` su `EcPoi` nel `wm-package` sono **inerti**: invitano a
  un uso (`$poi->related_url = [...]`) che fallisce con `SQLSTATE 42703`, perché la colonna non
  esiste. Il dato vive dentro `properties`.
- Il file `icons.json` scritto nel `beforeEach` dei test dell'import sta in un percorso che il
  service **non legge**: i test che si appoggiano a quel setup non verificano le icone come
  sembrerebbe.
- `SardegnaSentieriImportService::importPoiActivityTaxonomies()` è **codice morto**:
  `POI_ACTIVITY_VOCABULARIES` è un array vuoto.
- I job che scaricano i media hanno un timeout più lungo del tempo dopo il quale la coda li
  riassegna: un file grande può essere scaricato due volte. È una condizione preesistente, che i
  video da 78 MB rendono più probabile.

## Verifica sui dati veri

Import completo in locale, con il database allineato come farà il team su UAT — `migrate` per la
tabella nuova e le tre colonne di `entes` aggiunte a mano, senza ricreare il database.

| Dato | Risultato |
|---|---|
| Tipi di punto di interesse | 28, nessuno con il nome uguale all'identificativo |
| Collegamenti punto di interesse–percorso | 2436 |
| Collegamenti fra punti di interesse | 1136 |
| Percorsi vicini | 3034 |
| Allegati nella media library | 248 (163 file distinti) |
| Video | 7 |
| Foto con didascalia | 5006 |
| Percorsi con collegamenti esterni | 75 |
| Punti di interesse con collegamenti esterni | 47 |
| Punti di interesse con data di creazione | 971 su 971 |
| Enti con slug | 92 su 92 |

Due cose emerse solo da questa prova, che nessun test aveva colto:

1. **Il calcolo dei percorsi vicini si era fermato dopo due blocchi su sedici**, scrivendo 272
   coppie invece di 3034: nessun errore, nessun job fallito, nessuna riga di log. **Corretto**: il
   blocco successivo si accoda prima del lavoro, ogni blocco scrive una riga di log e `failed()`
   registra il blocco perso dicendo come rifarlo.
2. **Il batch dell'import è rimasto appeso** con 53 job in sospeso, quindi la catena di fine import
   non è partita da sé. Causa: i riavvii di Horizon fatti durante lo sviluppo, non un difetto del
   codice.

## Correzioni dalla review formale (09/11)

La review ha trovato tre difetti bloccanti, tutti corretti e coperti da test.

- **I collegamenti erano in sola lettura solo sui percorsi.** Dalla scheda di un punto di
  interesse si potevano ancora aggiungere o togliere a mano percorsi e altri punti di interesse,
  che l'import avrebbe cancellato al primo giro. La regola vive ora in un trait
  (`app/Nova/Concerns/HasMirroredRelations.php`) montato su entrambe le Resource: era stata
  scritta su una sola delle due classi che la devono rispettare.
- **La stessa foto veniva importata due volte sullo stesso contenuto**: 57 file per 120 righe, e
  in 46 casi una copia aveva la didascalia e l'altra no. Nascono da due esecuzioni dello stesso
  job dei media, che hanno un `timeout` di 300 secondi mentre la coda riconsegna dopo 90
  (`queue.connections.redis.retry_after`): `syncOne()` riconosce i file già presi leggendo i media
  che il modello ha in memoria, e due esecuzioni non si vedono fra loro. Ora i due job dichiarano
  `WithoutOverlapping` per contenuto, e `pruneDuplicates()` tiene una sola riga per indirizzo di
  origine — quella con la didascalia — riassorbendo anche le copie già create.
- **La sezione «Al rilascio» non nominava i comandi dello stato di validazione.** Senza
  `configure-taxonomy-terms` e `sardegnasentieri:move-validation-status` i 742 percorsi con uno
  stato lo mostrano vuoto e un reimport non lo ricostruisce.

**Il confronto con la sorgente è stato eseguito sui dati veri**, cosa che il piano chiedeva e che
non era mai stata fatta: `sardegnasentieri:compare-source --only=shard` dà 33 differenze, tutte
dovute alle copie doppie qui sopra e ai file che su Drupal rispondono 404. Legami fra contenuti
(2436 e 1136), collegamenti esterni (206) e conteggi degli enti combaciano esattamente.

Due osservazioni emerse e **non** corrette, da valutare a parte:

- `properties.forestas.source_relations` tiene i nid di Drupal e `properties` finisce nel GeoJSON
  pubblico: il nuovo sito e l'app riceveranno identificativi di un sistema che sta per essere
  spento. La chiave `forestas` era già pubblica prima di questo lavoro.
- Il campo dello stato di validazione costa quattro query per riga nella lista dei percorsi. Le
  altre trentatré per riga che quella pagina già paga vengono da oc:8910, che è su `develop`.
