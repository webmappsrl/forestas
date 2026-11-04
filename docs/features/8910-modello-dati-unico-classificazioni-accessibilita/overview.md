> Ticket: oc:8910

# Modello dati unico per classificazioni e accessibilità — parte di Forestas

> Le due tabelle, il modello, la Resource Nova e l'indicizzazione stanno nel package:
> `wm-package/docs/features/8910-modello-dati-unico-classificazioni-accessibilita/overview.md`.

## Cosa cambia

Forestas è il primo shard che usa il modello nuovo, ed è l'unico posto in cui lo si può provare
su dati veri. Qui nascono le tassonomie e i termini decisi nella call del 29/10/2026, l'import da
Sardegna Sentieri impara a scriverli, e le chiavi custom che oggi stanno dentro
`properties->forestas` e si usano per filtrare diventano colonne.

Dopo questo lavoro un sentiero di Forestas ha una difficoltà **per ogni modo di fruizione** —
oggi impossibile, perché `properties->difficulty` è un campo unico — e porta il grado di
accessibilità e le disabilità accolte come dati relazionati, non come chiavi dentro un JSON.

## Perché

I dati esistono già e nessuno li vede. Nella copia del database Drupal il vocabolario
`categorie_fruibilita_sentieri` — così lo chiama Drupal — è **gerarchico a due livelli**, cioè ha
già la forma che il modello nuovo costruisce: nove radici e i loro figli.

```
Trekking            → Turistico · Escursionistico · Escursionista Esperto · Esc. Esperto Attrezzato
Mountain Bike       → MTB_TC_Grado 0-1 · MTB_MC_Grado2 · MTB_BC_Grado3 · MTB_OC_Grado4 · MTB_EC_Grado5-6
Ippovia             → ippo_FACILE · ippo_MEDIO · ippo_DIFFICILE
Nordic Walking      → nordic_FACILE · nordic_MEDIO · nordic_IMPEGNATIVO
Mobilità dolce      → difficoltà 1 (bassa) · 2 (media) · 3 (alta)
Accessibili dis. motorie     → AT · AE · AEE
Accessibile dis. Sensoriali  → AT · AE · AEE
Accessibile dis. Cognitiva   → AT · AE · AEE
Sentiero didattico           → (nessun figlio)
```

Sono 759 relazioni su 614 sentieri. Nel backend di Forestas, intanto, `properties->difficulty` è
vuoto su tutte e 780 le tracce, e le diciotto chiavi `access_*` ereditate dal progetto ITACA non
esistono nemmeno come chiave nel JSON. Tutto quel contenuto oggi si perde nell'import.

## Requisiti

- [ ] Le tassonomie e i termini nascono da un **comando**, `sardegnasentieri:configure-taxonomy-terms`,
      sul modello di `sardegnasentieri:configure-layers` che già esiste per i layer. Ha due modi:
      di default **allinea** — per ogni tassonomia e ogni termine guarda se esiste già per
      `identifier`, aggiunge ciò che manca, aggiorna nome e descrizione, non cambia gli id e non
      tocca le tassonomie create a mano da Nova; con `--fresh` **riparte da vuoto**, svuotando e
      ricreando. Ha anche `--dry-run`, che mostra cosa farebbe senza farlo.
      Si scrive con `insertOrIgnore` e rilettura, non con `firstOrCreate` né catturando
      l'eccezione: sotto transazione PostgreSQL una violazione di unicità aborta la transazione
      (oc:8607). Un seeder di una riga lo invoca, così `db:seed` continua a funzionare in locale
      e l'elenco dele tassonomie vive in un posto solo.
- [ ] **Il comando lo lancia l'import**, così nessuno deve ricordarsene: l'import con `--reset`
      lo chiama con `--fresh`, l'import orario senza. I due import non convivono — sono i due rami
      di `routes/console.php:34-44`, scelti da `SARDEGNASENTIERI_DAILY_RESET` — ma sul ramo orario
      azzerare i termini romperebbe le relazioni dei sentieri non reimportati, che sono la maggior
      parte: lì si allinea e basta.
- [ ] Il reset svuota il **relazione** e non la tassonomia: `taxonomy_termables` per le righe di
      tracce e POI va cancellata a mano in `ImportSardegnaSentieriCommand::resetData()`, accanto a
      `taxonomy_activityables`, `layerables` ed `enteables`, perché il pivot polimorfo non ha
      chiave esterna verso `ec_tracks` e il `CASCADE` non lo raggiunge; dopo `RESTART IDENTITY` le
      righe vecchie si attaccherebbero ai sentieri nuovi con lo stesso id. `taxonomy_terms` invece
      non si tocca.
- [ ] I termini sono creati **con i nomi decisi in call**,
      non con quelli di Drupal: la tassonomia è «Difficoltà mountain bike», non «Mountain Bike», e
      il termine è «MC», non «MTB_MC_trail_Grado2».
- [ ] Ogni termine che viene da Drupal porta nell'`identifier` il termine di origine, nella forma
      `sardegnasentieri:tid:<tid>`, coerente con la convenzione già usata per le attività
      (`sardegnasentieri:type:sentiero`). L'import si aggancia a quello e non al nome, così
      rinominare un termine da Nova non rompe l'import.
- [ ] Le tassonomie create sono queste. Il nome è in italiano e traducibile, l'`identifier` è in
      inglese come ogni identificatore del repo:

      | `identifier` | nome | termini |
      |---|---|---|
      | `hiking-difficulty` | Difficoltà escursionistica | T · E · EE · EEA |
      | `mtb-difficulty` | Difficoltà mountain bike | TC · MC · BC · OC · EC |
      | `horse-difficulty` | Difficoltà ippovia | facile · medio · difficile |
      | `nordic-walking-difficulty` | Difficoltà Nordic Walking | facile · medio · impegnativo |
      | `educational-trail-difficulty` | Difficoltà sentiero didattico | facile · medio · impegnativo |
      | `soft-mobility-difficulty` | Difficoltà mobilità dolce | facile · medio · difficile |
      | `inclusivity-difficulty` | Difficoltà di accessibilità | AT · AE · AEE |
      | `inclusivity-disabilities` | Disabilità accolte | fisica e motoria · sensoriale · cognitiva e psichica |
      | `itineraries-and-paths` | Itinerari e cammini | da Drupal |
      | `route-type` | Tipo percorso | da definire |
      | `themes` | Tematismi | da definire |
- [ ] **Ogni tassonomia ha la sua icona, e l'hanno anche i termini che il sito mostra come
      simbolo.** L'icona è il nome di un glifo della icon font del package, nella forma `txn-*`,
      come già per i tipi di POI (`txn-olive-tree`, `txn-spring`) e per le attività
      (`txn-hiking`). I tre termini delle disabilità accolte ne hanno una ciascuno, perché la
      call le vuole come icone sulla scheda del sentiero:

      | termine | icona |
      |---|---|
      | Fisica e motoria | `txn-mobility-disability` |
      | Sensoriale | nessuna per ora: nel font esistono solo `txn-visual-disability` e `txn-hearing-disability`, separate, perché la categoria unica non esisteva nel vecchio modello |
      | Cognitiva e psichica | `txn-cognitive-disability` |

      I termini delle scale di difficoltà non hanno icona: T, E, EE si mostrano come sigle e come
      tacche, non come simboli.
- [ ] «Difficoltà di accessibilità» (`inclusivity-difficulty`) nasce con `single_choice` a `true`: Nova mostra una select a
      scelta singola e l'import scrive una relazione solo. Non c'è nessun vincolo nel database — vedi
      la decisione in `notes.md` — quindi l'unicità la garantiscono le due strade che scrivono, e
      la sorgente che ne porta due produce un'anomalia.
- [ ] **L'import scrive le relazioni per tutte le tassonomie che Drupal sa riempire**: le cinque scale
      di difficoltà da `field_frubilita` (759 righe su 614 sentieri), il grado di accessibilità e
      le disabilità accolte dalle tre scale `Accessibili dis. *`, gli itinerari e cammini da
      `field_tipologia_itinerario` sui nodi itinerario, la zona geografica da
      `field_zona_geografica` (665 sentieri). Un sentiero reimportato non accumula doppioni: i
      relazioni di una tassonomia si sostituiscono, non si aggiungono.
- [ ] **I tre interi dell'inclusività e i due testi liberi non vengono da Drupal**, che non ha
      nessun campo per loro: restano in inserimento manuale da Nova, e l'import non li tocca mai —
      né li azzera a un reimport. Attenzione: l'import riscrive `properties->forestas` per intero,
      quindi i due testi vanno protetti con un merge e non lasciati alla sostituzione.
- [ ] **Anche il grado di accessibilità e le disabilità accolte si compilano a mano**, e l'import
      li sostituisce **solo se** Drupal ha almeno un termine di accessibilità per quel sentiero.
      In tutta la copia Drupal ce ne sono **due righe su 614 sentieri**: trattarle come le scale
      di difficoltà cancellerebbe il lavoro dell'operatore a ogni reimport del nodo.
- [ ] L'import collassa le **tre** scale di accessibilità di Drupal in **una**, e la categoria
      diventa un termine della tassonomia «Disabilità accolte»: il termine Drupal
      «Accessibile dis. Sensoriali → AE» produce due relazioni, il grado `AE` e la categoria
      «sensoriale». «Sensoriale» accorpa udito e vista, che in Drupal e nel vecchio modello ITACA
      erano separati.
- [ ] L'import **ignora** le relazioni che puntano a una **radice** invece che a un figlio — 67 righe
      in Drupal: «Mountain Bike» 29, «Trekking» 20, «Nordic Walking» 9, «Ippovia» 7, «Sentiero
      didattico» 2. Non sono difficoltà, e non vanno nemmeno convertite in attività: le attività
      di un sentiero vengono da `field_tipologia_sentiero`, un campo suo che l'import legge già
      (`SardegnaSentieriDatabaseSource.php:113`). Dedurle dalla difficoltà ne attribuirebbe di
      inesistenti.
- [ ] I due sentieri Drupal che hanno due gradi nella stesso tassonomia vengono segnalati come
      anomalia, non scartati in silenzio.
- [ ] «Mobilità dolce» **non** viene aggiunta come attività: le sue difficoltà si importano (sono
      circa 55 relazioni, tutti figli con grado), ma se debba esistere come *attività* è una
      questione ancora aperta con il cliente — se vada aggiunta al format consegnato al SUS — e
      non si decide inventando un identifier.
- [ ] Le Resource Nova di Forestas estendono quelle del package, non le duplicano:
      `app/Nova/EcTrack.php` usa il generatore di select per tassonomia del package e vi aggiunge
      le tassonomie che riguardano solo Forestas — tipo percorso, tematismi, itinerari e cammini.
- [ ] **L'inclusività vive qui, non nel package**: nasce dal regolamento tecnico della Regione
      Sardegna e riguarda il Catasto di Forestas. `ec_tracks` prende tre colonne intere nullable,
      con il nome dello shard come prefisso e il controllo dell'intervallo in validazione — gli
      intervalli possono cambiare ogni anno per determinazione del Tavolo Tecnico Regionale:

      | colonna | voce | intervallo |
      |---|---|---|
      | `forestas_inclusivity_physical` | `AF`, accessibilità fisica | 11-55 |
      | `forestas_inclusivity_aids` | `AA`, presenza di ausili | 1-23 |
      | `forestas_inclusivity_digital` | `AD`, accessibilità digitale | 0-22 |

- [ ] I due testi liberi, massimo 30 caratteri, stanno accanto alle altre chiavi di Forestas:
      `properties->forestas.inclusivity_independent_use` (fruibilità in autonomia) e
      `properties->forestas.inclusivity_assistance` (disponibilità di assistenza). Restano in
      `properties` perché sono frasi, e il testo traducibile di un contenuto passa da lì.
- [ ] Il punteggio di inclusività (somma dei tre interi, 12-100) **non è memorizzato**: si calcola
      e si deposita nell'indice Elasticsearch insieme alla classe A1-A6, e in Nova si vede in
      sola lettura sul dettaglio del sentiero.
- [ ] Le soglie delle sei classi stanno in `config/forestas.php`, non nel codice: A1 da 80
      compreso in su, A2 da 57 a 79. Quando le soglie cambiano, le classi già scritte nell'indice
      non si aggiornano da sole: serve un reindex, e va detto nella procedura.
- [ ] Il blocco Nova «Inclusività» sta su `app/Nova/EcTrack.php` e **solo lì**: le select delle
      due tassonomie `inclusivity-difficulty` e `inclusivity-disabilities`, i tre interi, i due
      testi e il punteggio in sola lettura.
- [ ] Le tre colonne nuove sono ri-esposte piatte nel GeoJSON, nel punto che
      `GeoJsonService::getModelAsGeojson()` del package ha già predisposto — il commento «Merge
      accessibility columns (if present) into GeoJSON properties» con lo spread vuoto.
- [ ] **Il tab «Accessibility» sparisce dalle Resource di Forestas**, sia da `app/Nova/EcTrack.php:59`
      sia da `app/Nova/EcPoi.php:62`: sono le diciotto chiavi ereditate dal progetto ITACA, e sul
      database di Forestas non sono valorizzate né su 780 tracce né su 976 POI. Al suo posto, solo
      su `EcTrack`, compare il blocco «Inclusività» del package.
- [ ] **Nel package non si rimuove nulla**: i campi `access_*` e `properties->difficulty` restano,
      perché sono mappati dall'import GeoHub (`config/wm-geohub-import.php:388` e `480-493`) e
      vivono sugli shard che importano da lì. Si toglie solo ciò che Forestas monta.
- [ ] La Resource dei termini compare nel menu `Taxonomies` di Forestas, accanto alle tassonomie
      esistenti (`app/Providers/NovaServiceProvider.php:100`), e da lì si crea una tassonomia nuova
      senza passare dal codice.
- [ ] Le chiavi di `properties->forestas` che si usano per filtrare o ordinare diventano colonne
      di `ec_tracks` su Forestas, con il nome in inglese:

      | chiave oggi | colonna | tracce valorizzate |
      |---|---|---|
      | `properties->forestas.codice` | `forestas_registry_code` | 627 |
      | `properties->forestas.data_rilievo` | `forestas_survey_date` | 209 |
      | `properties->forestas.zona_geografica` | `forestas_geographic_area` | 779 |

      Il resto di `properties->forestas` resta dov'è.
- [ ] **Ogni colonna che uno shard aggiunge a una tabella del package porta il nome dello shard
      come prefisso.** Senza, il giorno che il package aggiunge a `ec_tracks` una colonna con lo
      stesso nome, il suo `ALTER TABLE` fallisce proprio sugli shard che se l'erano già creata, e
      fallisce al `migrate` del deploy. È una regola che vale oltre questo ticket: va proposta in
      `## Regole del repo` del `CLAUDE.md`.
- [ ] Il reindex a fine import tiene conto dei termini nuovi. Attenzione alla differenza già nota
      nel repo: un **mapping** cambiato non si applica con il reindex normale, serve
      `scout:import` (oc:8681).

### Le avvertenze convergono nel modello unico

Deciso in corsa, dopo aver visto il modello funzionare sui dati veri: `taxonomy_warnings` e
`taxonomy_warningables` sono **solo di Forestas** — nel `wm-package` non c'è una riga su di loro —
e contengono 13 avvertenze con 818 relazioni, tutte importate da Drupal e nessuna creata a mano.
Sono quindi il caso più pulito per provare che una tassonomia esistente si riduce a una tassonomia
senza perdere niente.

- [ ] Nasce la tassonomia `warnings`, «Avvertenze», icona `txn-alert`, a scelta multipla. La crea
      `ConfigureTaxonomyTermsCommand` **senza termini**: i tredici termini li porta l'import, come
      oggi, e domani possono cambiare.
- [ ] I termini mantengono l'`identifier` che hanno già, `sardegnasentieri:warning:<tid>`, e i
      nomi tradotti in italiano e inglese che Drupal fornisce. L'import continua ad agganciarsi
      all'`identifier`, non al nome.
- [ ] **Le due tabelle spariscono**, insieme a `App\Models\TaxonomyWarning`,
      `App\Models\TaxonomyWarningable` e alla relazione `taxonomyWarnings()` di `EcTrack`. I due
      file di migration che le creano si cancellano e al loro posto ne arriva una sola che fa
      `dropIfExists`: su UAT e in locale le tabelle esistono e vanno tolte, su un'installazione
      nuova la migration non trova niente e non fa danni.
- [ ] **I dati non si migrano: li riscrive l'import.** Le 13 avvertenze e gli 818 relazioni vengono
      tutti da Drupal e l'import li ricrea a ogni giro; su UAT il reset notturno li rifà comunque
      ogni mattina. Copiarli con una migration sarebbe codice usa e getta per un dato che si
      rigenera da solo. Il prezzo: fra la migration e il primo import, le avvertenze non ci sono.
- [ ] **La vista Nova delle avvertenze resta quella di oggi**, allo stesso indirizzo
      (`/nova/resources/taxonomy-warnings`) e nella stessa voce di menu: una Resource che elenca
      i soli termini della tassonomia `warnings` e, sul dettaglio di ognuno, le tracce che lo
      portano. Cambia solo la tabella sotto. È il primo esempio di una **vista per tassonomia**: la
      classe base sta nel package, perché serve a ogni shard che voglia dare a una tassonomia una
      voce di menu propria, e la sottoclasse di Forestas è una decina di righe.
- [ ] Sulla scheda del sentiero le avvertenze smettono di essere un campo a sé e compaiono nel tab
      «Classifications» come le altre tassonomie, che l'elenco lo legge dal database.
- [ ] Le avvertenze entrano nell'indice Elasticsearch sotto `terms`, dove oggi non arrivano
      affatto.
- [ ] Il reset dell'import perde le tre righe che cancellavano a mano le avvertenze
      (`ResetSardegnaSentieriCommand.php:100-103`): le relazioni le copre già la cancellazione di
      `taxonomy_termables`.

## Rischi

- **I dati compilati a mano spariscono al reset notturno.** I tre interi e i due testi non
  derivano da nessuna sorgente e stanno su `ec_tracks`, che il reset tronca
  (`ImportSardegnaSentieriCommand.php:810`); su UAT gira ogni mattina alle 06:00
  (`SARDEGNASENTIERI_DAILY_RESET=true`, scheduler in `routes/console.php:41`). **Accettato dal
  dev**: non siamo in produzione e al cliente serve provare il funzionamento. Le strade per
  proteggerli sono in `notes.md`.
- **Il pivot sopravvive al `TRUNCATE` e con `RESTART IDENTITY` si riattacca ai sentieri
  sbagliati**, perché una relazione polimorfa non può avere una chiave esterna. Mitigato dal
  requisito che aggiunge `taxonomy_termables` alla cancellazione del reset, come già fatto tre
  volte per `taxonomy_activityables`, `layerables` ed `enteables`.
- **Il collasso delle tre scale di accessibilità potrebbe perdere informazione** se un sentiero
  avesse gradi diversi per categorie diverse. Misurato sulla copia di UAT: le relazioni di
  accessibilità in Drupal sono **due in tutto**, sui nodi 1330 e 3901, che sono sentieri diversi.
  Oggi il caso non esiste.
- **`educational-trail-difficulty` nasce e nessun dato la riempie**: in Drupal «Sentiero
  didattico» non ha figli, e le 2 righe che lo puntano sono l'attività senza grado. La tassonomia
  resta vuota finché Forestas non comincia a compilarla.
- **Fra la migration che droppa le due tabelle e il primo import, le avvertenze non esistono.**
  Su UAT la finestra dura fino al reset delle 06:00, in locale fino al primo
  `sardegnasentieri:import`. Accettato perché nessuna avvertenza è stata creata a mano: sono
  tutte e tredici di Drupal.
- **Le relazioni scritte a mano da Nova spariscono al reset** insieme a quelle di Drupal, perché
  l'import rigenera solo i secondi. Vale per tematismi e tipo percorso, che da Drupal non
  vengono. Stessa accettazione dei punteggi.

## Out of scope

- **Il blocco «Sentieri per tutti» nella scheda sentiero del sito** — quattro barre a sei tacche,
  la classe scritta per esteso, il grado mostrato in due punti: è oc:8908.
- **I tematismi come elenco di valori.** La tassonomia nasce vuota: la lista la prepara Webmapp e
  la approva Forestas, e in call non è stata definita.
- **«Tipo percorso» come elenco di valori.** Stessa cosa: l'espressione non compare nemmeno nella
  call del 29/10.
- **`stato_di_validazione`.** Diventa uno stato nativo del sentiero quando Drupal sarà dismesso,
  ed è un lavoro suo.
- **Togliere `properties->forestas` per intero.** Escono tre chiavi, non tutte.
- **Far convergere la vecchia accessibilità nel modello nuovo.** Le diciotto chiavi `access_*`
  del package — fruibilità per categoria, livelli, descrizioni — andranno espresse come tassonomie
  e termini come tutto il resto, ma è un lavoro che riguarda il package e tutti gli shard che
  importano da GeoHub, non solo Forestas. **Ticket a parte**, da aprire.

## Moduli toccati

| File | Cosa |
|---|---|
| `database/migrations/<data>_create_taxonomy_terms_table.php` | pubblicata dal package |
| `database/migrations/<data>_create_taxonomy_termables_table.php` | pubblicata dal package |
| `database/migrations/<data>_add_forestas_columns_to_ec_tracks.php` | solo Forestas: le tre dell'inclusività più `forestas_registry_code`, `forestas_survey_date`, `forestas_geographic_area` |
| `app/Console/Commands/ConfigureTaxonomyTermsCommand.php` | nuovo: tassonomie e termini, con `--fresh` e `--dry-run` |
| `database/seeders/TaxonomyTermSeeder.php` | una riga: invoca il comando |
| `routes/console.php` | nulla da schedulare a sé: il comando lo chiama l'import |
| `app/Console/Commands/ImportSardegnaSentieriCommand.php` | `resetData()` svuota anche `taxonomy_termables` per tracce e POI |
| `app/Services/Import/Source/SardegnaSentieriDatabaseSource.php` | legge `field_frubilita` con la gerarchia |
| `app/Services/Import/Source/DrupalRecordReader.php` | query sui termini e sulle radici |
| `app/Services/Import/SardegnaSentieriImportService.php` | scrive le relazioni, collassa le tre scale, segnala le anomalie |
| `app/Nova/EcTrack.php` | le select per tassonomia, le colonne nuove |
| `app/Providers/NovaServiceProvider.php` | la Resource dei termini nel menu `Taxonomies` |
| `config/forestas.php` | soglie delle classi A1-A6 |

---

## Aggiornamento del 3 novembre — cosa è cambiato rispetto a sopra

Le righe qui sotto **prevalgono** su quelle sopra dove le contraddicono. La parte generale —
rinomina in «tassonomia», vincoli nel database, campo unico con icona, dipendenze nei dati,
filtri sui Layer — è nell'overview del package, in coda.

### Le tassonomie sono undici, non dodici

- [x] **`themes` (Tematismi) e `itineraries-and-paths` (Itinerari e cammini) sono state
      ritirate.** La prima nasceva vuota e la lista non è mai stata definita; la seconda si
      sovrapponeva a «Tipo percorso», che è la stessa informazione detta meglio. Il comando le
      rimuove (`RETIRED`), e `RENAMED` porta avanti gli identifier cambiati in corsa.
- [x] L'import è stato corretto di conseguenza: `field_tipologia_itinerario` scrive su «Tipo
      percorso».

### La scheda del sentiero

- [x] Sopra «Blocchi Dettaglio» compare una sezione a tab: **Generale · Attività ·
      Inclusività**, con «Generale» aperta per prima. Le Attività sono la prima tab dopo
      Generale perché le altre dipendono da loro.
- [x] «Proprietà» è stata estratta da dove stava e spostata **in fondo alla pagina**.
- [x] I campi dell'inclusività (i tre interi, i due testi, il punteggio in sola lettura) stanno
      nella tab «Inclusività» tramite `taxonomyTabExtraFields()`, non in un blocco a sé.
- [x] «Accessibile» **non è un campo**: non esiste nessun interruttore sì/no. Un sentiero è
      accessibile quando almeno un campo di inclusività è valorizzato. Deciso in call.

### Le tre attività che reggono i layer

- [x] Le righe `sardegnasentieri:type:sentiero`, `:itinerario` e `:cammino` di
      `taxonomy_activities` **non sono state cancellate**: i due layer del sito le usano via
      `whereHas('taxonomyActivities')`. Sono **nascoste** in Nova (`legacyTaxonomies()`), perché
      come attività non hanno senso.
- [x] Cancellarle davvero richiede prima di spostare i due layer su «Tipo percorso» e di
      correggere `syncTrackActivities()` nell'import: **non è in questo lavoro**.

### Elasticsearch

- [x] L'alias `ec_tracks` puntava a due indici, e sul più recente c'era un filtro
      `must_not _index` lasciato da uno `scout:import` interrotto: si scriveva sul nuovo e si
      leggeva da uno fermo al 6 ottobre. L'alias è stato rifatto senza filtro e **l'indice vecchio
      `ec_tracks_1791277311` è stato cancellato**.

### Cosa resta aperto

- **L'icona di «Difficoltà mobilità dolce»** è ancora `txn-bike` ed è sbagliata: è la difficoltà
  per chi cammina piano, non per le biciclette. Va scelta.
- **L'automatismo «percorsi accessibili»** — accendere l'attività quando c'è almeno una disabilità
  accolta — è confermato in due call ma resta un «nice to have», non fatto.
- **57 tracce hanno solo il termine Drupal `trekking`** (46 con la difficoltà già valorizzata) e
  non `hiking`: su quelle i campi che dipendono da `hiking` non compaiono. I due sono termini
  Drupal distinti — 452 tracce solo `hiking`, 97 entrambi.
- **«Percorsi accessibili» come attività** va tolta a regime, ma si tiene per lo storico.

### Moduli toccati, in aggiunta a quelli sopra

| File | Cosa |
|---|---|
| `app/Nova/EcTrack.php` | sezione a tab prima di «Blocchi Dettaglio», Proprietà in fondo, campi inclusività nella tab, attività legacy nascoste |
| `app/Console/Commands/ConfigureTaxonomyTermsCommand.php` | undici tassonomie, `RENAMED`, `RETIRED`, `ACTIVITY_NAMES`, accensione per App, `properties.tab` e `depends_on` |
| `app/Jobs/Import/RecalculateSardegnaSentieriLayersJob.php` | considera anche i layer che filtrano per termine |
| `app/Nova/Filters/TaxonomyVocabularyFilter.php` | etichetta «Vocabolario di origine»: qui «vocabolario» è quello di Drupal |
