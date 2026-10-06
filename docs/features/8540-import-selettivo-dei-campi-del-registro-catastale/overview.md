> Ticket: oc:8540

# Import selettivo dei campi del registro catastale

## Cosa cambia

Il job del registro catastale (`ImportRegistroCatastaleJob`), che oggi si limita a specchiare il
foglio Google in `registro_catastale_rows` (oc:8539), dopo il mirror scrive anche alcuni campi
sui sentieri (`EcTrack`) a cui ogni riga è agganciata:

| Colonna del foglio | Intestazione | Destinazione sul sentiero | Regola |
|---|---|---|---|
| D | «Origine (da)» | `properties->from` | scritto solo se `from` è vuoto (vince Drupal) |
| E | «Destinazione (a)» | `properties->to` | scritto solo se `to` è vuoto (vince Drupal) |
| F | «EVENTIUALE Meta intermedia» | `properties->via` (campo nuovo del package) | sempre, se la cella è piena |
| G | «lunghezza (m)» | `properties->manual_data->distance` (km) | sempre, se la cella è piena e sanitizzabile |
| H | «T. percorrenza (A)» | `properties->manual_data->duration_forward` (minuti) | sempre, se la cella è piena e sanitizzabile |
| I | «T. percorrenza (R)» | `properties->manual_data->duration_backward` (minuti) | sempre, se la cella è piena e sanitizzabile |

Le celle si cercano per intestazione normalizzata, come fa già `RegistroCatastaleRowParser`, non
per lettera.

I valori di lunghezza e tempi passano da un sanitizzatore che normalizza le varianti presenti nel
foglio con regole decise una per una. Un valore che il sanitizzatore non sa leggere, o che chi ha
compilato il foglio segna come dubbio, non si scrive e diventa un'anomalia del registro.

A un codice del catasto resta agganciata **una sola** riga del registro. Se il matcher ne trova
più di una, resta agganciata quella trovata dal link; se nessuna ha il link, non se ne aggancia
nessuna. In entrambi i casi il sentiero diventa un'anomalia e non riceve valori.

Il job non salva il modello intero: aggiorna solo le chiavi JSON che gli competono, con un update
atomico, per non cancellare quello che la catena DEM scrive nello stesso momento.

Nel package `wm-package` nasce la chiave `via` accanto a `from` e `to`: il DTO
`EcTrackPropertiesData` e il pannello «Proprietà» della Resource EcTrack la mostrano fra «da» e «a»,
con etichetta «meta intermedia». Vedi l'overview del package con lo stesso slug.

## Perché

Il registro catastale è il foglio che Forestas tiene con il CAI: contiene dati che Drupal non ha
(meta intermedia, origine e destinazione dove mancano) e valori di lunghezza e tempi misurati sul
campo, a differenza di quelli di Drupal, generati da un calcolo superato e già ignorati da oc:8641.
Nella call tecnica del 14/09/2026 si è deciso che, per lunghezza e tempi, il registro vince su
Drupal («Ignoriamo i valori da drupal. Cioè, o il Dem o l'Excel», Piccioli), e che origine e
destinazione si prendono dal registro solo dove Drupal non le ha («è presente già su Drupal,
lasciamo quello che abbiamo trovato su Drupal», Piccioli).

La meta intermedia la richiede lo schema di accatastamento CAI, la prevede l'iter di accatastamento
2026 e la chiede SUS (Saba, 14/09).

Fino al go-live il foglio resta la fonte di verità e si sincronizza a ogni giro; dal go-live
Forestas smette di aggiornarlo e i valori si correggono in Nova.

## Requisiti

- [ ] Un sanitizzatore puro (nessun DB, nessun HTTP) converte lunghezza e tempi. Regole della
  **lunghezza**, in metri → km:
  - spazi iniziali e finali tolti (`1.000 ` → 1 km);
  - punto seguito da esattamente 3 cifre = separatore delle migliaia (`9.967` → 9,967 km);
  - intero = metri (`100` → 0,1 km);
  - punto o virgola seguiti da 1 o 2 cifre = decimale in km (`2.6` → 2,6 km, `2,35` → 2,35 km).
- [ ] Regole dei **tempi**, `h:mm` → minuti interi:
  - spazi attorno ai due punti tolti (`03: 00` → 180);
  - lettera `O` al posto dello zero (`O1:30` → 90);
  - `:.`, `-.`, `.` o `-` fra ore e minuti letti come `:` (`01:.20` → 80, `01-.30` → 90,
    `1.10` → 70);
  - testo dopo l'orario scartato (`01:40 antiorario` → 100, `01:40 senso orar` → 100);
  - apostrofo = minuti (`15'` → 15);
  - intero senza unità: si prova in ore e in minuti e si tiene la lettura che, con la lunghezza
    della stessa riga, dà una velocità a piedi fra 1 e 6 km/h (`2` con 5.600 m → 120; `40` con
    993 m → 40); se sono plausibili entrambe, nessuna, o la riga non ha lunghezza →
    **non sanitizzabile**;
  - cella con `?`, o con un secondo orario nel testo (`2:45   ???`,
    `1:00 (cartello genna Eidadi dice 1:30)`) → **non sanitizzabile**.
- [ ] Ogni regola del sanitizzatore ha il suo test, e ogni valore fuori formato presente oggi nel
  foglio (DB locale, `registro_catastale_rows`) è un caso di test con il risultato atteso.
- [ ] I testi di D, E, F si normalizzano prima di scriverli: ritorni a capo e spazi ripetuti
  diventano un solo spazio (`Arcu Su⏎Mannau` → `Arcu Su Mannau`), spazi iniziali e finali tolti.
- [ ] A un codice del catasto resta agganciata al più una riga: fra più righe candidate vince
  quella trovata dal link; se nessuna ha il link, nessuna è agganciata.
- [ ] Nuova anomalia del registro `RIGHE_MULTIPLE`, provenienza `registro`, una per sentiero con
  più righe candidate, con nel contesto le righe coinvolte (foglio e numero di riga). Su quel
  sentiero il job non scrive nulla.
- [ ] Il job scrive solo sui sentieri con una riga agganciata e senza anomalia `RIGHE_MULTIPLE`.
- [ ] Lunghezza e tempi: a ogni giro il valore sanitizzato del registro sovrascrive quello in
  `manual_data`, anche se un operatore lo ha modificato in Nova. Una cella vuota o non
  sanitizzabile non scrive nulla su quel campo.
- [ ] Il job non tocca gli altri campi di `manual_data` (dislivelli, quote), che restano degli
  operatori.
- [ ] Origine e destinazione: il testo del registro va in `from`/`to` solo se il campo del sentiero
  è vuoto. Non si crea nessun POI.
- [ ] Meta intermedia: la cella piena va in `properties->via`.
- [ ] Un sentiero che non compare nel registro, o compare senza aggancio, non viene toccato: per
  lunghezza e tempi vale il DEM.
- [ ] Nuova anomalia del registro `VALORE_NON_SANITIZZABILE`, provenienza `registro`, una per cella
  non sanitizzabile, con nel contesto colonna e valore grezzo.
- [ ] Le due anomalie si vedono nella lista anomalie di Nova con titolo e testo, come quelle già
  esistenti del registro.
- [ ] Il job scrive con un update atomico sulle sole chiavi JSON che gli competono
  (`manual_data.distance`, `manual_data.duration_forward`, `manual_data.duration_backward`,
  `from`, `to`, `via`), senza caricare e salvare il modello: non cancella ciò che la catena DEM
  scrive nello stesso momento e non accoda la catena DEM.
- [ ] La scrittura sui sentieri avviene in una transazione, e solo se la lettura del foglio è
  riuscita: il «tutto o niente» del mirror vale anche qui.

## Rischi

- **Il job e la catena DEM scrivono `properties` nello stesso momento** (verificato: il package
  documenta che due processi che salvano l'intero `properties` si cancellano i valori a vicenda,
  `wm-package/docs/knowledge/dati-dem-e-valori-manuali.md`, oc:8660). Mitigato con l'update
  atomico sulle sole chiavi del registro. Un lock condiviso è stato scartato: `Bus::chain()` non
  rispetta `ShouldBeUnique` e il lock di oc:8660 vale solo all'accodamento, non durante
  l'esecuzione; coordinarli avrebbe richiesto di toccare la catena DEM del package.
- **Celle scritte a mano con formati non uniformi** (verificato sul mirror locale: 822 righe, circa
  15 valori fuori formato fra lunghezza e tempi). Mitigato con regole esplicite, una per variante
  reale, ciascuna con il suo test; ciò che resta dubbio diventa anomalia e lo corregge Forestas.
- **Righe diverse agganciate allo stesso sentiero dal ripiego sul numero** (verificato: sentieri
  252, 572, 689, tratti diversi dello stesso numero). Mitigato: resta agganciata solo la riga del
  link, il sentiero diventa anomalia e non riceve valori finché il foglio non viene sistemato.
- **Ritorni a capo nei testi** (verificato: circa 25 celle in D, E, F): il pannello «Proprietà» usa
  un input a riga singola che li toglie al primo salvataggio da Nova. Mitigato normalizzando gli
  spazi prima di scrivere.

## Out of scope

- Lo spegnimento della sincronizzazione del foglio al go-live.
- Un Field che mostri sul sentiero la riga del registro accanto ai valori manuali: il valore grezzo
  si legge già nella Tab «Registro» della scheda del codice (oc:8539).
- Colonne L, M (sentieri correlati, itinerari sovrapposti): la piattaforma li calcola dalla
  geografia.
- Colonne O, P (comune, soggetto custode): restano solo nel mirror.
- Difficoltà (J, K, L) e livello di validazione (X): hanno la loro macro area nel tag.
- La rimozione del codice che importava i valori manuali da Drupal: è già stata fatta in oc:8641.
- L'aggiornamento di Elasticsearch e delle tile dopo la scrittura: l'import da Drupal ha lo stesso
  limite.

## Moduli toccati

Repo `forestas`:

- `app/Services/RegistroCatastale/` — sanitizzatore nuovo; servizio nuovo che applica le righe ai
  sentieri; `RegistroCatastaleMatcher` e `RegistroCatastaleImporter` per la regola «una sola riga
  per codice»; due classi nuove in `AnomalyTypes/` (`ValoreNonSanitizzabile`, `RigheMultiple`);
  `RegistroAnomalyTypes` per registrarle.
- `app/Jobs/Import/ImportRegistroCatastaleJob.php` e `app/Console/Commands/ImportRegistroCatastaleCommand.php`
  — richiamano la scrittura sui sentieri dopo il mirror.
- `lang/` — titoli e testi delle due anomalie, in tutte le lingue presenti.
- `tests/` — test del sanitizzatore, della regola «una sola riga», della scrittura sui sentieri.
- `docs/knowledge/registro-catastale.md` — aggiornata a fine lavoro.

Repo `wm-package`: vedi `wm-package/docs/features/8540-import-selettivo-dei-campi-del-registro-catastale/overview.md`.
