> Ticket: oc:8700

# Resource Nova filtrabile per le righe del registro catastale

> Questa overview copre la parte di **forestas**. La restrizione per ruolo del Catasto sta nel
> package: `wm-package/docs/features/8700-resource-nova-filtrabile-per-le-righe-del-registro-catastale/overview.md`.

## Cosa cambia

In Nova, sezione Catasto, compare una nuova voce «Righe del registro»: l'elenco di tutte le righe
del registro catastale (il foglio Google condiviso con il CAI), comprese quelle non agganciate a
nessun codice del catasto, filtrabile e ricercabile.

- **Index:** Tab (nome della tab del file Google, es. `AREA G (Nuoro+SS+Gallura)`) · Area ·
  Settore · Numero come sul foglio (es. `100`, `100 A`, `162`) · Codice agganciato (link alla
  scheda del codice, «—» se la riga non è agganciata). Ordinamento di default come nel file: tab,
  poi riga dell'Excel (`sheet_name`, `row_number`, in `defaultOrderings()`: un `orderBy` in
  `indexQuery()` arriverebbe dopo il `latest(id)` di Nova e non conterebbe). L'ordine alfabetico
  delle tab coincide oggi con quello del file (B, C, D, E, F, G, T).
- **Filtri:** Tab (le tab presenti nel mirror), Area, Settore, Agganciato a un codice (sì / no).
  Oggi ogni tab contiene una sola area, quindi Tab e Area danno le stesse righe: l'Area resta
  perché, se un domani si aggiunge una tab che non corrisponde a un'area, la differenza si vede.
- **Ricerca:** per numero (scrivendo `101` si trovano `101` e `101A` in tutte le tab).
- **Detail:** identico alla Tab «Registro» della scheda del codice — le celle del foglio
  nell'ordine del foglio, poi tab, riga e data di import — prodotto dallo stesso codice
  (`RegistroTabRenderer`), così le due viste non divergono. Per una riga non agganciata il detail
  aggiunge una riga di testo: non è agganciata a nessun codice, e se è finita in anomalia si trova
  in Catasto › Anomalie, filtro provenienza Registro. Il filtro «Agganciato» resta sì/no: il motivo
  (numero prenotato, anomalia, `RIGHE_MULTIPLE`) lo dice la lista delle anomalie, non si duplica qui.
- **Sola lettura:** nessuna creazione, modifica o cancellazione; il mirror lo scrive solo l'import.

Il parser non salva il numero come si legge sul foglio: lo divide in settore e numero a due cifre
(`162` → settore `1`, numero `62`, `RegistroCatastaleRowParser::number()`), e una variante assente
vale `'0'`. Per questo la colonna Numero e la ricerca usano il numero **ricomposto**: settore +
numero a due cifre + variante se diversa da `'0'`. La ricerca lo fa con un'espressione SQL
(`Column::raw()` di Nova, per esempio `concat(sector, lpad(number::text, 2, '0'), nullif(variant, '0'))`),
non con `number` fra i campi cercabili: su PostgreSQL Nova userebbe `ilike` su una colonna intera,
che va in errore. Le righe con solo il link e nessun numero leggibile mostrano «—».

Per rendere filtrabili area, settore e numero, l'import **normalizza** quei valori in colonne vere
di `registro_catastale_rows`: `area`, `sector`, `number`, `variant`. Il parser li calcola già
(`RegistroCatastaleRowParser`) per l'aggancio al codice, ma oggi l'importer non li salva. Le
`cells` restano invariate.

La Resource nuova segue la regola di visibilità del Catasto introdotta nel package da questo stesso
ticket: la vedono solo Administrator ed Editor, con una Policy di forestas che richiama la regola del package.

## Perché

Il foglio Google smetterà di essere aggiornato: l'elenco deve prenderne il posto come luogo dove
consultare il registro. Oggi le stesse informazioni si vedono solo aprendo un codice alla volta,
nella Tab «Registro», e solo per le righe agganciate: le 245 righe su 822 senza codice (dati del
DB locale) non sono visibili da nessuna parte.

Filtrare sui valori grezzi delle `cells` darebbe risultati sbagliati:

- il numero è scritto in forme diverse (`320/B`, `101A`, `401 A`, `100 (S.I.)`), che il parser
  riporta a numero + variante;
- 33 righe hanno la cella dell'area vuota perché sul foglio l'area è scritta solo sulla prima riga
  di un gruppo: il parser assegna loro l'area della riga sopra, un filtro sul jsonb non le
  troverebbe.

## Requisiti

- [ ] La create di `registro_catastale_rows` (`database/migrations/2026_09_29_000001_create_registro_catastale_rows_table.php`) ha le colonne nullable `area`, `sector`, `number`, `variant`, indicizzate per i filtri. Nessuna migration aggiuntiva.
- [ ] `RegistroCatastaleImporter` scrive nel mirror area, settore, numero e variante calcolati da `RegistroCatastaleRowParser`, per tutte le righe, agganciate e no.
- [ ] Nuova Resource Nova in `app/Nova/` sul model `App\Models\RegistroCatastaleRow`, in sola lettura, con le colonne dell'index descritte sopra e ordinamento di default per tab e riga.
- [ ] Filtri Tab, Area, Settore, Agganciato a un codice; ricerca per numero.
- [ ] Il detail mostra lo stesso contenuto della Tab «Registro» del codice, generato dallo stesso metodo di `RegistroTabRenderer`.
- [ ] La Resource usa il trait `HidesWhenTrailRegistryDisabled` del package (nascosta a dominio `trail_registry` spento) e una Policy di forestas per `RegistroCatastaleRow`, registrata in `AppServiceProvider::boot()`, che consente `viewAny` e `view` con la stessa regola del Catasto, chiamando il metodo del package (`TrailRegistryPolicy::allows()` o nome equivalente) invece di riscrivere i ruoli: se la regola cambia nel package, le righe del registro la seguono. Senza policy Nova non protegge il detail aperto per URL (vedi l'overview del package).
- [ ] Voce «Righe del registro» nella sezione Catasto di `NovaServiceProvider`, dopo le voci del package e prima di «Documentazione API SUS».
- [ ] Le stringhe nuove hanno traduzione in `lang/it.json` (lingua di default, `APP_LOCALE=it`) e `lang/en.json`.
- [ ] Test: colonne normalizzate scritte dall'import (area ereditata, numero con variante, riga non agganciata); filtri; visibilità per ruolo; detail uguale alla Tab.
- [ ] Submodule `wm-package` aggiornato al commit con la regola dei ruoli.
- [ ] `docs/knowledge/registro-catastale.md` aggiornata: colonne normalizzate e la Resource nuova.

## Rischi

- **Su UAT la tabella `registro_catastale_rows` va ricreata a mano**, perché si modifica la create
  invece di aggiungere una migration. È un mirror che l'import riscrive a ogni giro: non si perde
  nessun dato, basta un import dopo la ricreazione. **La ricreazione va fatta insieme al deploy:** se
  il codice nuovo gira sulla tabella vecchia, `ImportRegistroCatastaleJob` fallisce con
  `column "area" does not exist`, la transazione torna indietro e con lei si ferma anche la
  scrittura dei valori sui sentieri (oc:8540). L'errore si vede solo fra i job falliti di Horizon.
- **Gli id delle righe cambiano a ogni import orario**: un link al detail di una riga salvato o
  condiviso smette di funzionare al giro successivo. Si accetta: l'elenco si consulta filtrando,
  non conservando link.

## Out of scope

- Cosa succede alle righe al go-live, quando il foglio non si sincronizza più (spegnimento della
  sincronizzazione, `cascadeOnDelete` della FK verso i codici con il reset notturno): non riguarda
  questo ticket.
- Esportazione dell'elenco in CSV o Excel.
- Normalizzazione delle altre colonne del foglio (lunghezza, tempi, origine, destinazione…): restano
  nelle `cells` e si leggono nel detail.

## Moduli toccati

Tutti in **forestas**:

- `database/migrations/2026_09_29_000001_create_registro_catastale_rows_table.php` — colonne normalizzate
- `app/Models/RegistroCatastaleRow.php` — `$fillable`, phpdoc
- `app/Services/RegistroCatastale/RegistroCatastaleImporter.php` — scrittura delle colonne
- `app/Nova/RegistroTabRenderer.php` — metodo della tabella delle celle riusabile dal detail
- `app/Nova/RegistroCatastaleRow.php` — **nuovo**, Resource
- `app/Nova/Filters/` — **nuovi** filtri Tab, Area, Settore, Agganciato
- `app/Policies/RegistroCatastaleRowPolicy.php` — **nuovo**, Policy
- `app/Providers/AppServiceProvider.php` — registrazione della Policy
- `app/Providers/NovaServiceProvider.php` — voce di menu
- `lang/it.json`, `lang/en.json`
- `tests/Feature/RegistroCatastale/`, `tests/Feature/TrailRegistry/` — test
- `docs/knowledge/registro-catastale.md`
- `wm-package` — puntatore del submodule
