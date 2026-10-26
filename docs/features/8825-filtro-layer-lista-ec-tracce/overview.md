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

## Requisiti

- [ ] `EcTrackLayerFilter::apply()` segue i quattro passi di «Cosa cambia»; la modalità si legge
  con `Layer::isAutoTrackMode()`
- [ ] Le tracce associate si filtrano sulla query di Nova (`whereHas` sulla relazione `layers`
  della traccia), così si combinano in AND con gli altri filtri
- [ ] Il controllo «il layer ha tracce associate» è una query `exists`, senza caricare la relazione
- [ ] Etichetta, chiave (`features_by_layer_ecTracks`) e opzioni del filtro restano quelle di oc:8806
- [ ] Feature test su `/nova-api/ec-tracks`, un caso per passo, più la combinazione con «Aree
  catastali»

## Rischi

- **Le associazioni si mostrano come sono**: fra le 50 tracce locali di «Cammini di Sardegna» c'è
  almeno un sentiero (G 126). La correzione dei dati è separata.
- **Il reset notturno lascia in `layerables` gli id vecchi** (`TRUNCATE ec_tracks RESTART IDENTITY`,
  nessuna chiave esterna): le associazioni manuali di Cammini e Sentieri accessibili possono puntare
  ad altre tracce. Follow-up separato, non in questo ticket.

## Out of scope

- La lista dei POI, che ha lo stesso filtro: follow-up separato.
- La correzione delle tracce associate ai layer.
- La conservazione delle associazioni manuali al reset notturno: follow-up separato.
- `FeaturesByLayerFilter` e `onLayer()` nel wm-package.
- La risposta al cliente, su oc:8827 insieme a oc:8806.

## Moduli toccati

Tutti nel repo **forestas**:

- `app/Nova/Filters/EcTrackLayerFilter.php` — `apply()` con i quattro passi
- `tests/Feature/EcTrack/EcTrackFiltersTest.php` — casi del filtro dei layer
- `docs/knowledge/8806-filtro-ec-tracce.md` — il limite su «Cammini» e «Sentieri accessibili»
  diventa la regola nuova
