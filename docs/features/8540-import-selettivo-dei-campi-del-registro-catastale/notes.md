> Ticket: oc:8540

# Notes — Import selettivo dei campi del registro catastale

## Divergenze dal piano, task per task

### Task 1 sanitizzatore
La review ha trovato due letture troppo larghe dei tempi, assenti dal foglio di oggi (verificato sul mirror locale: 0 casi): minuti oltre 59 (`1:75`) e cifre attaccate dopo i minuti (`1:305`, `1:30:45`). Ora sono illeggibili, con tre casi di test in più. Coerente con la regola del dev: ciò che non si risolve si esplicita.

### Task 3 scrittura sui sentieri
Il valore della costante `RegistroAnomalyTypes::VALORE_NON_SANITIZZABILE` è `registro_valore_illeggibile`, non `registro_valore_non_sanitizzabile`: la colonna `trail_registry_anomalies.type` è `varchar(32)` (migration dello stub del package) e la stringa del piano, di 33 caratteri, faceva fallire l'insert. La migration del package non si tocca: è condivisa fra gli shard.

## Bug trovati

- `wm-package/tests/Unit/Dto/OsmNodePoiDataTest.php` fallisce già prima di questo lavoro con «Test case `Wm\WmPackage\Tests\TestCase` can not be used … already uses the test case»: dichiara `uses(TestCase::class)` mentre `tests/Pest.php` lo applica già a tutta la cartella. Non toccato: fuori dal perimetro del ticket.

## Decisioni

- Nel package i test nuovi non dichiarano `uses(TestCase::class)`: lo applica già `tests/Pest.php`, e il doppione fa fallire Pest (il piano lo prescriveva, era sbagliato).
- Le celle segnaposto (`-` nella meta intermedia, `?` in una destinazione) si scrivono come testo qualsiasi: la correttezza del foglio è responsabilità di chi lo redige (decisione del dev).
- La stima del ticket è stata saltata su richiesta del dev.
- Dopo la review finale, il context di `RIGHE_MULTIPLE` porta `code` (così la Tab «Registro» la trova anche senza sentiero), foglio e riga della riga rimasta agganciata, e `rows` come coppie `{sheet,row}` tradotte in lettura; il testo introduttivo delle anomalie in Nova elenca anche i due tipi nuovi.
- `updated_at` si scrive nel fuso dell'app e non con `now()` di PostgreSQL (UTC nella sessione): la colonna è `timestamp without time zone`, e Eloquent scrive nel fuso dell'app.
- La mappatura colonne → campi e le regole di lettura delle celle sono anche nella description del ticket e nel tag `[CALL][FORESTAS][2026] excel registro sentieri` (richiesta del dev).

## Follow-up

- Limite noto: quando in un `RIGHE_MULTIPLE` resta agganciata la riga del link, la Tab «Registro» mostra la riga e non l'avviso.
- Casi limite accettati, senza occorrenze nei dati di oggi: stesso sentiero agganciato da due codici diversi; `syncFromTo` dell'import Drupal che azzera il capo mancante e fa riscrivere al registro quando il sentiero cambia su Drupal.

- Spegnimento della sincronizzazione del foglio al go-live: fuori perimetro per scelta del dev.
