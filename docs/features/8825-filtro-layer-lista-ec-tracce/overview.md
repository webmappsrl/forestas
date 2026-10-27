> Ticket: oc:8825

# Filtro layer nella lista EC Tracce: «Cammini di Sardegna» e «Sentieri accessibili» mostrano tutte le tracce

## Cosa cambia

Nella lista Nova delle EC Tracce il filtro dei layer (`EcTrackLayerFilter`, introdotto da oc:8806)
usa oggi `apply()` del package, cioè `onLayer()`, che seleziona le tracce candidate per attività e
aree del layer. Un layer senza attività né aree non ha condizioni e restituisce tutte le tracce.

Dopo questo lavoro il filtro sceglie la fonte in base al layer:

1. layer in modalità **manuale** → le tracce associate (tabella `layerables`);
2. layer in modalità **automatica** con un'attività o un'area → `onLayer()`, come oggi;
3. layer in modalità automatica senza filtro ma con tracce associate → le tracce associate;
4. negli altri casi → `onLayer()`, come oggi.

Sui dati locali (tutti e quattro i layer sono in modalità automatica):

| Layer | Attività | Tracce associate | Oggi | Dopo |
|---|---|---|---|---|
| 11 Sentieri | `sardegnasentieritypesentiero` | 614 | 614 | 614 (passo 2) |
| 10 Itinerari | `sardegnasentieritypeitinerario` | 165 | 165 | 165 (passo 2) |
| 8 Cammini di Sardegna | nessuna | 50 | 779 | 50 (passo 3) |
| 9 Sentieri accessibili | nessuna | 2 | 779 | 2 (passo 3) |

Di conseguenza funziona anche la combinazione con «Aree catastali»: «Cammini di Sardegna» + un'area
dà i cammini del layer che passano per quell'area.

### Seconda parte (27/10): «Aree catastali» diventa una multiselect

Il filtro «Aree catastali» (`EcTrackCadastralAreaFilter`, introdotto da oc:8806 e già in
`develop`) oggi estende il `BooleanFilter` di Nova: una casella per ogni area, tutte sempre
visibili nel menu dei filtri. Dopo questo lavoro estende `MultiselectFilter` del pacchetto
`outl1ne/nova-multiselect-filter`: una tendina con ricerca, in cui le aree scelte restano visibili
come etichette (lo stesso componente della multiselect nel form delle App, che però è un campo e
non un filtro).

Il risultato del filtro non cambia: le aree scelte valgono in OR, nessuna area scelta non
restringe nulla, il filtro si combina in AND con quello dei layer. Cambiano solo l'aspetto e il
formato del valore che arriva ad `apply()`: una lista di id (`[12, 15]`) invece di
`[id area => true/false]`.

## Perché

Il cliente ha segnalato su UAT che il filtro dei layer restituisce sempre tutte le tracce (oc:8827,
21/10). Dopo oc:8806 il problema resta per i due layer senza attività. Per l'app le tracce di un
layer sono quelle associate (`LayerService`, `wm-package/src/Services/Models/LayerService.php:37-46`);
la lista Nova mostrava invece le candidate.

Decisioni del 26/10:

- **Si corregge il filtro, non la configurazione dei layer**: un'attività per i cammini non esiste,
  e la configurazione andrebbe ripetuta su ogni ambiente.
- **I layer con un filtro automatico restano su `onLayer()`**: per loro la tabella `layerables` è
  una copia calcolata dallo stesso filtro, aggiornata da un job con almeno 5 minuti di attesa
  (`EcTrackObserver.php:146-149`) sulla coda `default`; leggerla peggiorerebbe Sentieri e
  Itinerari, che oggi funzionano.
- **oc:8825 si fa dopo oc:8806** e la risposta al cliente copre i due ticket, una volta che i
  filtri funzionano in produzione.

Decisioni del 27/10 (seconda parte):

- **Giuseppe Bonfanti, allo scrum del 27/10, chiede la multiselect al posto delle caselle**:
  «Aree catastali io lo visualizzerei come una select che puoi selezionare più cose
  contemporaneamente perché prende molto spazio», anche in vista di un filtro per province. Il
  risultato del filtro va bene così com'è («come risultato va bene… era esattamente quello che
  volevo»).
- **Si fa in oc:8825, sullo stesso branch e sulla stessa PR**, perché il filtro è già in `develop`
  ed esce in produzione insieme a questa correzione.
- **Pacchetto `outl1ne/nova-multiselect-filter` invece della multiselect nativa di Nova**: la
  nativa (`MultiSelectFilter`, da un campo `MultiSelect` filtrabile) è una `<select multiple>` HTML
  senza ricerca, dove le scelte multiple richiedono Cmd/Ctrl+clic. Un utente che non lo sa la usa
  come una select singola. Il pacchetto `outl1ne/nova-multiselect-field`, già nel progetto, non
  offre un filtro. La serie 5.x del pacchetto dei filtri richiede `laravel/nova: ^5.0`.
- **La verifica di tutti i filtri della lista e la rimozione del filtro App, chieste nello stesso
  scrum, vanno in oc:8875**, così eventuali altri bug si rendicontano lì.

## Requisiti

- [x] `EcTrackLayerFilter::apply()` segue i quattro passi di «Cosa cambia»; la modalità si legge
  con `Layer::isAutoTrackMode()`
- [x] Le tracce associate si filtrano sulla query di Nova (`whereHas` sulla relazione `layers`
  della traccia), così si combinano in AND con gli altri filtri
- [x] Il controllo «il layer ha tracce associate» è una query `exists`, senza caricare la relazione
- [x] Etichetta, chiave (`features_by_layer_ecTracks`) e opzioni del filtro restano quelle di oc:8806
- [x] Feature test su `/nova-api/ec-tracks`, un caso per passo, più la combinazione con «Aree
  catastali»

Seconda parte (27/10):

- [x] `outl1ne/nova-multiselect-filter` `^5.0` nel `composer.json` di forestas, installato senza
  aggiornare altri pacchetti: `laravel/nova` resta su 5.7.6
- [x] `EcTrackCadastralAreaFilter` estende `MultiselectFilter`; `options()` restituisce
  `id => nome` (formato del pacchetto, inverso di quello di oggi), con lo stesso ordinamento per nome
- [x] `apply()` riceve una lista di id: aree in OR, lista vuota o assente = nessuna restrizione,
  stessa query di oggi (sorgente letta nelle due forme, nessuna collisione con gli `osm2cai_id`)
- [x] Etichetta («Cadastral areas» / «Aree Catastali») e chiave del filtro invariate
- [x] I testi del componente visibili all'utente (segnaposto, «nessun risultato» e simili) sono in
  italiano e in inglese
- [x] I 7 test delle aree catastali in `EcTrackFiltersTest.php` riscritti con il valore come lista
  di id, stessi casi; un test controlla che Nova riceva il filtro con il componente del pacchetto e
  le opzioni `id => nome`
- [x] Prova a mano in Nova locale, poi la multiselect si mostra in locale ad Alessandro Peci prima
  di passare il ticket in `testing`

## Rischi

- **Le associazioni si mostrano come sono**: fra le 50 tracce locali di «Cammini di Sardegna» c'è
  almeno un sentiero (G 126). La correzione dei dati è separata.
- **Il reset notturno lascia in `layerables` gli id vecchi** (`TRUNCATE ec_tracks RESTART IDENTITY`,
  nessuna chiave esterna): le associazioni manuali di Cammini e Sentieri accessibili possono puntare
  ad altre tracce. Follow-up separato, non in questo ticket.
- **Una dipendenza nuova in forestas**: va installata senza far muovere `laravel/nova`, che non
  può salire oltre la 5.7.6 per la licenza scaduta. L'ultima versione del pacchetto (5.0.3) è del
  19/10/2026.

## Domande aperte

- ~~**Alessandro Peci conferma l'uso di `outl1ne/nova-multiselect-filter`?**~~ Risolta il 27/10:
  confermato in call, insieme al segnaposto «Seleziona area catastale» (vedi notes.md).

## Out of scope

- La lista dei POI, che ha lo stesso filtro: follow-up separato.
- La correzione delle tracce associate ai layer.
- La conservazione delle associazioni manuali al reset notturno: follow-up separato.
- `FeaturesByLayerFilter` e `onLayer()` nel wm-package.
- La risposta al cliente, su oc:8827 insieme a oc:8806.
- La verifica degli altri filtri della lista EC Tracce e la rimozione del filtro App: oc:8875.

## Moduli toccati

Tutti nel repo **forestas**:

- `app/Nova/Filters/EcTrackLayerFilter.php` — `apply()` con i quattro passi
- `tests/Feature/EcTrack/EcTrackFiltersTest.php` — casi del filtro dei layer
- `docs/knowledge/8806-filtro-ec-tracce.md` — il limite su «Cammini» e «Sentieri accessibili»
  diventa la regola nuova

Seconda parte (27/10), sempre in **forestas**:

- `composer.json`, `composer.lock` — `outl1ne/nova-multiselect-filter`
- `app/Nova/Filters/EcTrackCadastralAreaFilter.php` — classe base, `options()`, `apply()`
- `tests/Feature/EcTrack/EcTrackFiltersTest.php` — test delle aree catastali
- `lang/` — eventuali testi del componente in italiano e inglese
- `docs/knowledge/8806-filtro-ec-tracce.md` — il filtro delle aree diventa una multiselect
