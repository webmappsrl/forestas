# Classificazioni e inclusività su Forestas

Il modello unico dei tassonomie e dei termini sta nel package (`wm-package/docs/features/8910-modello-dati-unico-classificazioni-accessibilita/overview.md`).
Qui resta ciò che serve sapendo di stare su Forestas: da dove nascono tassonomie e termini, come li
scrive l'import, quali dati sono manuali e quali importati, come si calcola l'inclusività. (oc:8910)

## Come nascono tassonomie e termini

- **Un tassonomia è una riga di `taxonomy_terms` senza padre; un termine è una riga con
  `parent_id` che punta al tassonomia.** Si distinguono per `identifier`: quello del tassonomia è
  in inglese (`hiking-difficulty`, `inclusivity-difficulty`), quello di un termine che viene da
  Drupal è `sardegnasentieri:tid:<tid>`.
- **La fonte dell'elenco di sistema è il comando `sardegnasentieri:configure-taxonomy-terms`**
  (`app/Console/Commands/ConfigureTaxonomyTermsCommand.php`, metodo `taxonomies()`): nomi, icone,
  ordine e `single_choice` di ogni tassonomia stanno lì e in nessun altro posto. Il seeder
  `TaxonomyTermSeeder` lo invoca soltanto.
- **Senza opzioni allinea**: per ogni riga dell'elenco cerca per `identifier`, aggiunge ciò che
  manca, riallinea nome e icona, non cambia gli id e non tocca i tassonomie create a mano da Nova.
  **Con `--fresh` riparte da vuoto** (svuota `taxonomy_terms` e ricrea). **Con `--dry-run`** stampa
  cosa farebbe senza scrivere.
- **Lo lancia l'import**, nessuno deve ricordarsene: con `--reset` lo chiama con `--fresh`,
  senza `--reset` allinea soltanto (`ImportSardegnaSentieriCommand.php:150`). Sul ramo orario
  azzerare i termini romperebbe le relazioni dei sentieri non reimportati.
- Il reset (`resetData()`) cancella anche le relazioni di tracce e POI in `taxonomy_termables`, ma
  non il tassonomia: il pivot polimorfo non ha chiave esterna, e con `RESTART IDENTITY` le righe
  vecchie si attaccherebbero ai sentieri nuovi.

## Le avvertenze sono un tassonomia come le altre

Fino a oc:8910 le avvertenze erano una tassonomia a sé: `taxonomy_warnings`, il suo pivot
`taxonomy_warningables`, due modelli e una Resource Nova. Oggi sono il tassonomia `warnings`, e le
due tabelle non esistono più — la migration che le toglie è
`2026_11_03_120000_drop_taxonomy_warnings_tables.php`.

- la **tassonomia** la crea `sardegnasentieri:configure-taxonomy-terms`, vuota, con l'icona
  `txn-alert` e a scelta multipla;
- i **termini** li porta l'import da Drupal (`field_avvertenze_e_pericoli`) e tengono
  l'`identifier` che avevano prima, `sardegnasentieri:warning:<tid>`: rinominare un'avvertenza in
  Drupal non rompe il collegamento;
- i **relazioni** seguono la stessa regola di sostituzione delle scale di difficoltà, perché
  `warnings` è fra le `IMPORT_OWNED_VOCABULARIES`: dopo un reimport restano quelli che Drupal dice
  adesso, mai la somma.

**I dati non sono stati migrati:** li riscrive l'import, e il conto torna con la sorgente —
794 relazioni su 219 sentieri, quanti sono i riferimenti distinti in Drupal. Le 818 righe che
c'erano nella vecchia tabella ne contenevano una ventina di orfane, rimaste indietro perché il
pivot vecchio non veniva svuotato dal reset.

**La vista Nova è rimasta dov'era**, `/nova/resources/taxonomy-warnings` e la stessa voce di
menu: `App\Nova\TaxonomyWarning` ora estende `AbstractTaxonomyTermsResource` del package, dichiara
il tassonomia e tiene l'`uriKey` di prima. Sulla scheda del sentiero, invece, le avvertenze non
sono più un campo a sé: stanno nel tab «Classifications» con le altre tassonomie.

## Come l'import mappa i termini di Drupal

- **L'identifier `sardegnasentieri:tid:<tid>` è l'aggancio**: l'import cerca il termine per quello
  e non per nome, quindi rinominare un termine da Nova non rompe niente.
- **Le tre scale di accessibilità di Drupal collassano in una**, più la categoria di disabilità.
  «Accessibile dis. Sensoriali → AE» produce due relazioni: il grado `AE` in `inclusivity-difficulty`
  e «sensoriale» in `inclusivity-disabilities`. Il perché: la call del 29/10 ha stabilito che il
  grado è **uno solo per sentiero** (Saba: «la scala di difficoltà è unica per il sentiero»); la
  categoria dice per quali disabilità vale. La mappa è `ACCESSIBILITY_TIDS` nel service.
- **Le righe di `field_frubilita` che puntano a una radice** (Trekking, Mountain Bike, ...) **si
  ignorano**: non hanno un grado da scrivere, e le attività di un sentiero vengono da
  `field_tipologia_sentiero`, che l'import legge già. Dedurle dalla difficoltà ne attribuirebbe
  di inesistenti.
- Un tid sconosciuto, o due gradi diversi nella stesso tassonomia a scelta singola, non sollevano
  eccezioni: l'import tiene il primo e segna il caso in
  `properties->forestas->classificazioni_anomale`, oltre che nel log `import`.

## Quali dati sono manuali e quali importati

| Dato | Come si compila | Cosa fa l'import |
|---|---|---|
| Tre interi dell'inclusività (`forestas_inclusivity_physical`, `_aids`, `_digital`) | A mano, in Nova | Non li tocca |
| Due testi (`properties->forestas->inclusivity_independent_use`, `..._assistance`) | A mano, in Nova | Non li tocca |
| Grado di accessibilità e disabilità accolte | A mano, in Nova | Li sostituisce **solo se** Drupal ha almeno un termine di accessibilità per quel sentiero (oggi 2 sentieri su 614) |
| Le cinque scale di difficoltà (escursionistica, mtb, ippovia, nordic walking, mobilità dolce) | Da Drupal | Le **sostituisce a ogni import**: ciò che si compila a mano in queste tassonomie si perde al reimport del sentiero |

I tassonomie create da Nova (tematismi, tipo percorso) non sono toccate dall'import.

**I dati compilati a mano si perdono al reset notturno**: `ec_tracks` viene troncata
(`resetData()`), su UAT ogni mattina alle 06:00. È stato accettato in questa fase (non siamo in
produzione e al cliente serve provare il funzionamento); le strade per proteggerli sono in
`docs/features/8910-modello-dati-unico-classificazioni-accessibilita/notes.md`.

## «Accessibile» non è un campo, è una conseguenza

Decisione presa con il cliente nella call del 2 novembre 2026 e confermata il 3 (oc:8910).
**Non è ancora implementata**: nel codice non esiste nessuna derivazione di «accessibile», e
chi la cerca non la trova.

- **Niente interruttore sì/no.** Il cliente lo aveva chiesto; Alessio Piccioli ha risposto
  «basta che se c'è un solo elemento è sì, se non ce n'è neanche uno è no». Un sentiero è
  accessibile se almeno uno dei dati di inclusività è valorizzato. Resta da decidere quali dei
  sette elementi contano — i tre interi, i due testi, i due tassonomie — e se uno zero conta
  come valorizzato: `forestas_inclusivity_digital` parte da 0, quindi zero è un valore legittimo
  e non un campo vuoto.
- **Il tab resta visibile anche quando è tutto spento**, perché si veda che la classificazione è
  in corso: oggi sono due sentieri su trecento ad averla.
- **Come conseguenza, quando un sentiero avrà almeno una disabilità accolta, l'attività
  «percorsi accessibili» dovrà accendersi da sé.** È segnata come desiderabile, non obbligatoria.

### Nova e sito pubblico si comportano diversamente, e va bene così

- **In Nova i campi dell'inclusività si compilano su qualunque tipo di percorso**, itinerari
  compresi: Alessio Piccioli, il 3 novembre, «non deve esserci niente di legato». Infatti i due
  tassonomie `inclusivity-*` dichiarano solo `'tab' => 'Inclusivity'` e **nessun** `depends_on`,
  a differenza delle scale di difficoltà, che dipendono dalle attività
  (`ConfigureTaxonomyTermsCommand`).
- **Sulla scheda pubblica invece, per un contenuto di tipo itinerario, la sezione accessibilità
  non si mostra**: «l'itinerario non è classificato nel catasto» (Alessio Saba, 2 novembre).
  Quella regola vive nel sito, non in questo repository.
- Le due cose sembrano contraddirsi lette fuori contesto, ma parlano di due schermate diverse:
  **una dipendenza che nasconda l'inclusività agli itinerari non va aggiunta in Nova.**

## Il punteggio di inclusività

- **Derivato, mai memorizzato**: `App\Support\InclusivityScore::for()` somma i tre interi
  (12-100) e ne ricava la classe A1-A6; si deposita nell'indice Elasticsearch e in Nova si vede in
  sola lettura sul dettaglio.
- **`null` se manca anche uno solo dei tre valori**: un sentiero con il solo AF compilato non è
  in classe bassa, è non misurato.
- **Le soglie stanno in `config/forestas.php` (`inclusivity_classes`) e quelle di A3-A6 sono
  provvisorie.** La call ha fissato solo A1 (da 80 in su) e A2 (57-79): le altre vanno chieste a
  Forestas e sostituite con quelle del regolamento regionale.
- **Cambiando le soglie, le classi già nell'indice non si aggiornano**: serve un reindex. Basta
  quello normale e non `scout:import`, perché cambia il valore e non il mapping (a differenza di
  un mapping cambiato: oc:8681).
- Gli intervalli dei tre interi (11-55, 1-23, 0-22) sono validati in Nova, non nel database,
  perché possono cambiare ogni anno per determinazione del Tavolo Tecnico Regionale.

## Colonne di Forestas su `ec_tracks`

Le colonne che Forestas aggiunge a una tabella del package portano il prefisso `forestas_`
(`forestas_registry_code`, `forestas_survey_date`, `forestas_geographic_area`,
`forestas_inclusivity_*`): se il package aggiungesse una colonna omonima, il suo `ALTER TABLE`
fallirebbe al deploy.
