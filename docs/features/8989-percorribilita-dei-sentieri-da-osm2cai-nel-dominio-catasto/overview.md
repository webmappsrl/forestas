> Ticket: oc:8989
> Stato: approvata il 2026-11-16

# Percorribilità dei sentieri su Forestas

Questa overview copre la parte che vive nello **shard forestas**. La parte del dominio è in
`wm-package/docs/features/8989-percorribilita-dei-sentieri-da-osm2cai-nel-dominio-catasto/overview.md`.

## Cosa cambia

Forestas configura i valori della percorribilità, li riceve dall'import e li mostra: filtro della
ricerca, card nella dashboard di Nova, incongruenze catastali.

Contestualmente `validation-status` smette di parlare del terreno: i due termini che lo facevano si
spostano nella percorribilità, e restano cinque stati di pura pratica catastale.

## Perché

Oggi un sentiero ha un solo stato, che mescola due domande diverse — a che punto è la pratica, e
com'è il sentiero sul terreno. Un sentiero «Validato» chiuso da una frana risulta a posto, e chi
cerca un percorso non ha modo di sapere se ci può andare.

`decisioni.md` di oc:8783: «Due classificazioni distinte per sentieri e cammini, mai mescolate in un
solo valore», e fra ciò che manca «Percorribilità separata dalla validazione».

## Punto di partenza

- `ConfigureTaxonomyTermsCommand.php:113-120` crea la famiglia `validation-status`
  (`single_choice`) con sette termini: `validation-status-unverified`, `-not-walkable`,
  `-under-review`, `-pre-registration`, `-walkable`, più validato e certificato. Sono record in
  tabella, non valori nel codice.
- L'import da Sardegna Sentieri associa i tid di Drupal a quegli identifier in
  `SardegnaSentieriImportService::VALIDATION_STATUS_TERMS`.
- `app/Nova/Filters/EcTrackValidationStatusFilter.php:15` filtra con
  `whereHas('taxonomyTerms', fn ($terms) => $terms->where('taxonomy_terms.identifier', $value))`.
- Elasticsearch indicizza i termini sotto `terms.<identifier-famiglia>`; il filtro «Percorribilità»
  della ricerca lavora oggi su `terms.validation-status` raggruppato in fasce.
- `app/Nova/Dashboards/Main.php` contiene solo `new Help`: la dashboard è ancora quella di default
  di Nova.
- Sui conteggi del 19/10/2026 (`decisioni.md`, Geohub app 32, 625 sentieri): 152 «Percorribile»,
  19 «Non percorribile», 168 «In revisione-validazione», 271 «In pre-accatastamento o manutenzione»,
  8 «Validato», 6 «Non verificato», uno senza stato.

## Requisiti

- <a id="r1"></a>**R1** — La famiglia e i suoi termini li crea il **package** quando si accende il
  dominio: lo shard non li dichiara nel proprio comando delle tassonomie, altrimenti due elenchi
  scriverebbero la stessa riga e, divergendo, nascerebbero due famiglie. Su forestas la famiglia è
  `walkability-status`, con quattro stati: `walkability-status-unknown` (Sconosciuta), `-not-walkable` (Non percorribile),
  `-partially-walkable` (Parzialmente percorribile), `-walkable` (Percorribile). Il nome segue
  quello di `validation-status` perché le due compaiono sempre affiancate — in Elastic, in API e nei
  filtri — e perché la corrispondenza fra i due termini che cambiano famiglia resta leggibile.
  Ogni stato porta il proprio nome tradotto, l'icona e l'ordine,
  così che aggiungerne uno sia una riga nel comando dei termini e non richieda di toccare il codice.
  Come si colora resta una scelta del sito: il semaforo in home vive in `wp-forestas` e mappa gli
  identifier sui colori, come fa oggi con i sette di `validation-status`.
- <a id="r2"></a>**R2** — `validation-status` non contiene più «Non percorribile» e «Percorribile»:
  restano cinque stati che parlano solo della pratica catastale.
- <a id="r3"></a>**R3** — L'import smista: un sentiero la cui fonte dice «Percorribile» o «Non
  percorribile» riceve lo stato di percorribilità corrispondente, e la sua validazione diventa «Non
  verificato» — scritta esplicitamente, non lasciata vuota, perché un sentiero senza termine di
  validazione esce da ogni filtro e dai conteggi. Gli altri sentieri nascono con
  `walkability-status-unknown`, che è un termine associato come gli altri: nessun sentiero resta
  senza percorribilità.
- <a id="r8"></a>**R8** — I sentieri che oggi portano i due termini di terreno dentro
  `validation-status` passano alla nuova famiglia con un comando dedicato, rilanciabile e con una
  modalità di prova. Il comando lavora **per sentiero**, spostando i legami: non rinomina le righe
  dei termini, perché i termini nuovi esistono già e il comando dello shard, trovando sia il vecchio
  sia il nuovo identifier, salta la rinomina con un avvertimento e lascia i sentieri dov'erano.
- <a id="r4"></a>**R4** — La ricerca e Nova filtrano per percorribilità come già fanno per la
  validazione. In Nova il filtro è quello generico di oc:8988, istanziato con l'identifier della
  famiglia: questo lavoro non scrive una classe di filtro propria.
- <a id="r5"></a>**R5** — I due stati restano indipendenti: nessuno dei due si deriva dall'altro, e
  ogni combinazione si può salvare — un sentiero certificato e non percorribile è il caso di una
  frana recente, e deve essere rappresentabile.
- <a id="r6"></a>**R6** — Lo shard dichiara una incongruenza catastale: `missing_walkability`, un
  sentiero validato o certificato di cui non si conosce la percorribilità. Etichetta tradotta, come
  per gli altri termini del dominio.
- <a id="r7"></a>**R7** — La dashboard di Nova mostra lo stato del Catasto al posto della pagina di
  benvenuto di Nova: i numeri delle due incongruenze, che portano alla index filtrata, e la
  distribuzione dei sentieri per percorribilità. Le card vengono dal package, lo shard le monta
  sulla propria dashboard, e le vede **chiunque entri in Nova** — `Administrator`, `Editor`,
  `Validator`, `Contributor` — senza restrizione per ruolo: sono numeri d'insieme, non dati di un
  singolo contenuto. Restano fuori `Guest` e `Sus`, che in Nova non entrano affatto.

## Rischi

- **Il semaforo della home perde l'avvertenza sui sentieri chiusi, fra i due rilasci.** Oggi conta
  gli stati di validazione: sui numeri del 19/10 sono 160 verdi, 439 gialli, 19 rossi. Spostando i
  due termini di terreno, quei 171 sentieri passano a «Non verificato» e i conteggi diventano 8
  verdi, 0 rossi, 177 non verificati. Il sito non va in errore — «Non verificato» lo conosce già —
  ma per chi guarda la home **nessun sentiero risulta chiuso**, finché il ticket di `wp-forestas`
  non fa leggere al semaforo la percorribilità. *Mitigazione:* vincolo di sequenza dichiarato — il
  backend non va in opera su un ambiente pubblico prima che il sito sappia leggere la nuova
  famiglia. Su dev e UAT la finestra è innocua. Non esiste un rollback del solo backend che la
  chiuda: tornare indietro significa disfare lo sdoppiamento.
- **I conteggi di questa overview non vengono da questo database.** I 625 sentieri e la loro
  ripartizione sono presi dal `decisioni.md` di oc:8783, che li ha letti da Geohub app 32 il
  19/10/2026. Servono a dare un ordine di grandezza, non a dimensionare: prima della migrazione
  vanno ricontati su `ec_tracks`.
- **Il filtro «Percorribilità» della ricerca pubblica raggruppa oggi gli stati di validazione in
  fasce**, e quelle fasce sono configurazione dell'App, non codice. Chi le cambia, e su quale
  ambiente, non è coperto da questo lavoro: va verificato in fase di piano, altrimenti la ricerca
  continua a filtrare sul campo vecchio mentre il dato vero sta in un altro.

## Out of scope

- Fascia, indicatore a tacche e semaforo nel sito: vivono in `wp-forestas`, ticket a sé.
- «Temporaneamente interrotto» come stato: è il «campo avviso» deciso allo scrum del 29/10, altro
  ticket.
- Ricalcolo automatico della percorribilità da altri campi: il dato è e resta manuale.

## Moduli toccati

Previsti:

- `app/Console/Commands/ConfigureTaxonomyTermsCommand.php` — famiglia `walkability`, e rimozione
  dei due termini da `validation-status`
- `app/Services/Import/SardegnaSentieriImportService.php` — smistamento dei due valori, e scrittura
  esplicita di «Non verificato» sulla validazione dei sentieri smistati
- `app/Console/Commands/` — comando una tantum che sposta i sentieri già legati ai due termini,
  sul modello di `MoveValidationStatusToTermsCommand`
- registrazione del filtro generico di oc:8988 sulla Resource delle tracce, con l'identifier della
  percorribilità (nessuna classe di filtro nuova)
- `app/Nova/Dashboards/Main.php` — monta le card del Catasto che il package fornisce, al posto di
  `new Help`
- indicizzazione Elasticsearch dei termini della nuova famiglia
- `tests/Feature/`, `tests/Unit/`
