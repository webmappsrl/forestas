> Ticket: oc:8489

# Identificazione automatica del sentiero — parte forestas

Il ticket vive quasi interamente in `wm-package`, dominio opzionale `trail_registry`: registro dei codici, modello dell'istanza, service di dominio, comando di normalizzazione. **L'overview principale è là**, in `wm-package/docs/features/8489-identificazione-automatica-sentiero-codice-rei/overview.md`, e va letta prima di questa.

Qui sta solo ciò che non può stare nel package.

## Cosa cambia in forestas

Nasce un **menu `Catasto`**, distinto da `EC` che resta il catalogo e non viene toccato (`POI`, `Tracce`, `Enti`, `Layers`, `Feature Collections` restano come sono).

Il menu `Catasto` raccoglie tre voci:

| Voce | Resource | Dove vive |
|---|---|---|
| **Sentieri** | `EcTrack` con `indexQuery()` filtrata sui sentieri | forestas |
| **Istanze** | `TrailApplication` | package: arriva da sé a dominio acceso |
| **Registro dei codici** | il codice del registro | package: arriva da sé a dominio acceso |

**Nessuna Resource `Itinerario`**: nel Catasto sta solo ciò che si accatasta, e un itinerario non si accatasta — non ha un codice REI. Gli itinerari restano visibili in `EC → Tracce`, che continua a mostrare tutti i tracciati.

**`Istanze` e `Registro dei codici` non si scrivono qui: arrivano dal package.** Chi accende il dominio `trail_registry` le vede comparire senza configurare niente — il package registra le due Resource (`features.trail_registry.nova_resources`) e inietta la sezione di menu da sé, con lo stesso meccanismo già in uso per `Tools` (`WmPackageServiceProvider`, righe 557-660): se il consumer ha già una sezione con quel nome ci accoda le voci, altrimenti la crea.

Forestas quindi dichiara una sezione `Catasto` con la sola voce `Sentieri`, e le altre due si aggiungono da sole.

Se un domani serve customizzare una delle Resource del registro, la si estende e si pubblica `config/wm-package.php` mettendo la propria classe in `nova_resources` al posto di quella del package: resta una sola Resource registrata, nessuna ambiguità. Non serve farlo ora.

## Perché sta qui e non nel package

Il filtro è su `sardegnasentieri:type:sentiero` e `sardegnasentieri:type:itinerario`, identificatori che nascono dall'import da Sardegna Sentieri. Nel package sarebbero la Sardegna scritta dentro codice condiviso, che servirà anche Lombardia e Toscana — lo stesso motivo per cui dal registro sono stati tenuti fuori il nome dello sportello (`sus`) e un valore `import` su `source`.

Il pattern è già in uso nel progetto: le risorse Nova di forestas estendono quelle del package (`app/Nova/App.php` estende `Wm\WmPackage\Nova\App`).

## Perché i sentieri hanno una voce propria

Le due popolazioni sono oggetti diversi e si istruiscono in modo diverso. Misurato sul database:

| tipo | tracce | con `ref` | senza `ref` | km medi |
|---|---:|---:|---:|---:|
| `sardegnasentieri:type:sentiero` | 614 | 574 | 40 | 5.1 |
| `sardegnasentieri:type:itinerario` | 147 | **0** | 147 | 29.1 |
| *(nessun tipo)* | 6 | 6 | 0 | 3.7 |

**Nessun itinerario ha un numero: zero su 147.** Un itinerario è un aggregato di sentieri — cammini a tappe (CMSB, Cammino di Santu Jacu, Via dei Santuari), reti tematiche (C100T), il Sentiero Italia — e il codice REI non lo rappresenta: il numero è di due cifre dentro **un** settore, mentre la Via Catalana da 228 km ne attraversa otto. Non è che l'algoritmo sbaglierebbe il numero: il modello del codice non descrive quell'oggetto.

Solo i sentieri entrano nel registro dei codici. Tenerli in un unico elenco con gli itinerari rende invisibile la distinzione che decide chi è accatastabile — ed è la ragione per cui la voce sta nel menu `Catasto` e non è una seconda vista del catalogo.

## Requisiti

- [ ] Nuova sezione di menu **`Catasto`** con la voce `Sentieri`; `Istanze` e `Registro dei codici` si accodano dal package a dominio acceso
- [ ] Resource **`Sentiero`**: `EcTrack` con `indexQuery()` filtrata su `sardegnasentieri:type:sentiero`
- [ ] In index di `Sentiero`, una colonna con il **codice del registro**, letta dalla relazione e non da `ec_tracks`
- [ ] Il menu **`EC` resta invariato**: nessuna voce rimossa, `Tracce` continua a mostrare tutti i tracciati
- [ ] Le 6 tracce oggi senza tipo diventano `sentiero` — criterio «ha un `ref`», che vale per queste 6 e **non è un criterio generale** (40 sentieri non hanno `ref`)
- [ ] Nessuna Resource `Itinerario`, nessuna Resource per i tracciati senza tipo: decisioni esplicite del dev

## Come si realizza

**Cosa mostra `Sentiero`.** Eredita campi, filtri e azioni da `App\Nova\EcTrack`: index, detail ed edit restano quelli di oggi, con una sola aggiunta — **il codice del registro come colonna in index**, così in elenco si vede quale numero ha ciascun sentiero e quali non ne hanno ancora uno. È il dato che sostituisce `ref`, destinato a essere deprecato.

La colonna legge dal registro (relazione verso il codice attivo del sentiero), non da `ec_tracks`: il registro è la fonte unica e il prefisso non va duplicato.

**Una sola Resource nuova sul modello `EcTrack`.** `ec_tracks` resta una tabella unica e `EcTrack` un modello unico: cambia solo come Nova lo presenta. `Sentiero` estende `App\Nova\EcTrack` senza duplicarne i campi — cambia etichetta, `uriKey()` e `indexQuery()`.

Il menu si compone in `NovaServiceProvider::boot()`, dove le sezioni sono già strutturate; `Catasto` va dopo `Media`.

**Alternativa valutata e scartata:** una sola Resource con `MenuItem` a filtro preapplicato (`applyFilter()`). Eliminerebbe del tutto l'ambiguità sulla Resource canonica descritta nei Rischi, ma la scheda di dettaglio si intitolerebbe «Ec Track» invece di «Sentiero». Il dev ha scelto la Resource con l'etichetta propria.

## Da chiarire con Forestas

- **Ogni sentiero nasce da un'istanza, o d'ufficio si può creare un sentiero direttamente da `Catasto → Sentieri`?** La creazione su quella Resource esiste per eredità da `EcTrack`. Se viene usata, nasce un sentiero mai passato da un'istanza e a cui nessuno ha riservato un numero: resta fuori dal registro, e il suo codice risulta libero e proponibile a un'altra domanda. Se ogni sentiero deve passare da un'istanza, la creazione va disabilitata qui; se la creazione d'ufficio è legittima, serve un percorso che riservi il numero contestualmente. **In questo ciclo non si decide.**

L'elenco completo delle domande aperte è nell'overview del package.

## Rischi

- **La Resource canonica del modello resta ambigua, ma meno.** Nova risolve modello → Resource con un `first()` sulla collezione e memoizza il risultato (`Nova::resourceForModel()`, `vendor/laravel/nova/src/Nova.php`): con due Resource su `EcTrack` la vincente è decisa dall'ordine di scoperta dei file. Da lì passano i campi relazione — cinque `MorphToMany` in `app/Nova/Ente.php`, `BelongsToMany` in `app/Nova/EcPoi.php:67`, `MorphToMany` in `app/Nova/TaxonomyWarning.php:35` — quindi una riga di quegli elenchi può aprire un tracciato con l'intestazione «Sentiero» anche quando è un itinerario. Non dà errore: dà l'etichetta sbagliata. **Mitigato dalla scelta di non toccare il menu `EC`**: `Tracce` resta una voce viva, quindi `app/Nova/EcTrack.php` non rischia di essere cancellata come classe morta — che era il caso peggiore. Da verificare in ambiente quale delle due vince, e se non è `EcTrack` va ancorata esplicitamente.
- **Una traccia senza tipo non compare in `Sentieri`.** Oggi sono 6 e vengono sanate, ma il tipo arriva da Drupal e al prossimo import può mancare di nuovo: quel tracciato non si vede nel Catasto, trovabile solo con una query. **Rischio accettato dal dev**, che ha escluso sia una Resource di residuo sia l'inclusione dei senza-tipo in `Sentiero`. Diventa più grave da quando il registro entra in uso: un sentiero invisibile non entra nel registro, il suo numero risulta libero e può essere proposto a un'altra istanza. Attenuante: resta visibile in `EC → Tracce`, che non filtra nulla.
- **Gli elenchi relazione continuano a mescolare sentieri e itinerari.** I sette campi relazione sopra passano dal modello, non dalla Resource filtrata: la distinzione resta invisibile in gran parte del backoffice.
- **Creare un tracciato da `Sentiero` non gli attacca il tipo.** `indexQuery()` filtra l'elenco, non popola nulla in creazione: il record appena creato non compare nella Resource da cui lo hai creato. Se la creazione da queste Resource è un caso reale, il tipo va attaccato alla creazione.
- **`indexQuery()` non è una policy.** L'URL di `Itinerario` con l'id di un sentiero apre, mostra e salva senza obiezioni. Un test che verifica solo l'elenco dà falsa sicurezza proprio su questo.
- **Il valore del tipo non è validato all'import, e non viene corretto in questo ciclo** (decisione esplicita del dev). `SardegnaSentieriImportService::syncTrackType()` compone `'sardegnasentieri:type:'.$response->type` interpolando un campo Drupal: se a monte diventa `Sentiero` o `trail`, l'import crea in silenzio una nuova `TaxonomyActivity` e la Resource `Sentiero` si svuota, senza errori né log. **È il primo posto da guardare se un giorno la voce `Sentieri` risulta vuota o dimezzata.**
- **`syncTrackTaxonomies()` fa un `sync()` pieno delle activity e `syncTrackType()` ricompone subito dopo.** Chi riordina quelle righe, o aggiunge un altro `sync()` di activity, fa perdere il tipo a tutti i tracciati. Un import con `type` assente degrada un sentiero già classificato a senza-tipo.
- **Il discriminante vive in una sede impropria.** Un'*activity* nel package dice **come** si percorre un tracciato (`trekking`, `mtb`, `horse`), non **cosa** è: il valore ci è finito perché l'import mappa i "types" di Drupal sulle activities. È quindi una riga in una tabella pivot che si può staccare da Nova, e da cui dipende la comparsa in una voce di menu.
- **Il filtro è sul dato importato, non su una proprietà nostra.** Da quando il registro entra in uso, è l'essere passati per un'istanza a stabilire che un tracciato è un sentiero; finché l'import resta la fonte, un cambio a monte si riflette nel menu senza preavviso.

## Out of scope

- Il registro dei codici, il modello dell'istanza, il service di dominio, il comando di normalizzazione: tutto in `wm-package`
- **La normalizzazione dei 40 sentieri con il codice nel nome** (`(G 110)`, `( B 442 )`): in questo ciclo si segnalano e non si toccano — prima va capito lato Drupal cosa significhi l'assenza del `ref`
- L'interfaccia Nova per l'istruttoria delle istanze: oc:8491. Le voci `Istanze` e `Registro dei codici` in questo ciclo sono elenchi delle Resource del package, non il banco di lavoro dell'istruttoria
- Una Resource `Itinerario`: gli itinerari restano in `EC → Tracce`
- Filtri, azioni e schede proprie delle due nuove voci, oltre a quelli già ereditati dalla risorsa del package

## Moduli toccati

| File | Cosa |
|---|---|
| `app/Nova/Sentiero.php` | Resource su `EcTrack`, `indexQuery()` filtrata sui sentieri |

| `app/Providers/NovaServiceProvider.php` | nuova sezione `Catasto` con la sola voce `Sentieri`; menu `EC` invariato |
| `tests/Feature/` | `Sentiero` mostra solo i sentieri; il menu `EC` non perde voci |

`app/Nova/EcTrack.php` non viene modificata.

Il dominio `trail_registry` è già attivo in forestas (`WM_TRAIL_REGISTRY_ENABLED`, oc:8492): da qui arrivano solo gli stub di migration da pubblicare, nessuna modifica alla configurazione.
