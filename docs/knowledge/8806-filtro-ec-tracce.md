# Filtri della lista EC Tracce

## Come funziona oggi

Nella lista Nova delle EC Tracce, anche nelle relazioni delle tracce della detail view di un Ente
(«Track gestiti» e le altre, che usano lo stesso `filters()`), il menu dei filtri legge i termini
delle classificazioni scritti sulla traccia (oc:8988). Tutti i filtri usano il selettore del campo
`Choice` del package, con ricerca, icone e chip dei valori scelti: come è fatto sta in
`wm-package/src/Nova/Fields/Choice/README.md` («Il selettore nei filtri»).

| Filtro | Classe | Scelta |
|---|---|---|
| Stato validazione | `TaxonomyTermFilter(EcTrack::VALIDATION_STATUS_TAXONOMY)` | singola |
| Percorribilità | `TaxonomyTermFilter(<walkability_taxonomy>)`, solo con il dominio Catasto acceso | singola |
| Tipo percorso | `TaxonomyTermFilter('route-type', multiple: true)` | multipla |
| Disabilità accolte | `TaxonomyTermFilter('inclusivity-disabilities', multiple: true)` | multipla |
| Difficoltà di accessibilità | `TaxonomyTermFilter('inclusivity-difficulty', multiple: true)` | multipla |
| Aree catastali | `app/Nova/Filters/EcTrackCadastralAreaFilter.php`, su `ChoiceFilter` | multipla |

Nell'URL un filtro tassonomico ha la chiave `taxonomy_term_<identifier>`; le aree catastali hanno il
nome della classe, come ogni filtro che non ridefinisce `key()`.

- **I valori dello stesso filtro valgono in OR, filtri diversi in AND.** Una traccia senza termini
  in quella tassonomia non esce da un filtro attivo.
- **Il valore di un filtro tassonomico è l'identifier del termine**, che è stabile fra ambienti e
  finisce nell'URL. Un link con soli identifier non validi (un termine rinominato o ritirato) dà
  un elenco vuoto, mai tutte le tracce.
- **Il titolo di un filtro tassonomico è il nome della tassonomia**: le tassonomie di forestas hanno
  solo il nome italiano (`ConfigureTaxonomyTermsCommand`), quindi il menu resta in italiano anche
  con l'interfaccia in inglese, come i campi delle stesse tassonomie nel form.
- **La Percorribilità segue il dominio Catasto**: c'è solo con
  `wm-package.features.trail_registry.enabled`, e l'identifier si legge da
  `wm-package.features.trail_registry.walkability_taxonomy`, la stessa config che usano l'import e
  il dominio. A dominio spento la tassonomia non esiste e il filtro non avrebbe opzioni.
- **Lo «Stato validazione» del filtro propone tutti i termini della famiglia**, anche
  `validation-status-walkable` e `-not-walkable`, ritirati da oc:8989, finché esistono: servono a
  trovare le tracce che li hanno ancora. Il campo «Stato» del form propone invece solo i termini
  dichiarati (`EcTrack::validationStatusTerms()`). I ritirati restano in tabella se lo storico dei
  cambi di stato li nomina, e scelti nel filtro danno zero tracce, perché l'import non li scrive più.
- **Le aree catastali** sono le `TaxonomyWhere` di sorgente `geohub_conf_32`
  (`TaxonomyWhereLayersImportService::SOURCE_KEY`), in ordine di nome. Il valore è la lista degli
  id delle aree. La chiave e il formato sono quelli del vecchio filtro outline, quindi i link
  salvati con le aree continuano a filtrare e mostrano le chip anche con gli id scritti come
  numeri: li converte in stringa il componente del filtro.
- **Non ci sono** il filtro **Layer**, il filtro **Ruolo**, il filtro **App** (il campo del package
  è reso non filtrabile in `App\Nova\EcTrack::fields()`) né **Include/Exclude per id**:
  `App\Nova\EcTrack::filters()` scarta dal menu del package `FeaturesByLayerFilter`,
  `FeaturesIncludeByIds` e `FeaturesExcludeByIds`.
- **I link salvati con Layer, Ruolo o il vecchio Stato validazione non filtrano più**: Nova abbina
  i filtri dell'URL per `key()` e scarta in silenzio quelli che non conosce
  (`vendor/laravel/nova/src/Filters/FilterDecoder.php`). Un preferito «tracce da validare» apre
  la lista con tutte le tracce.

Con i filtri tassonomici si ottiene il contenuto dei quattro layer di tipologia:

| Layer | Filtro |
|---|---|
| Sentieri | Tipo percorso = Sentiero |
| Itinerari | Tipo percorso = Itinerario |
| Cammini di Sardegna | Tipo percorso = Cammino |
| Sentieri Accessibili | Difficoltà di accessibilità = AT, AE, AEE |

### Come le tracce finiscono nei layer (oc:8827)

I layer non servono più ai filtri della lista, ma restano il contenuto dell'app. Un layer di
tipologia è definito dai termini che gli danno il contenuto. Fino a oc:8827 erano attività; dal
modello unico delle classificazioni (oc:8910) sono termini di `taxonomy_terms`, gli stessi della
tabella sopra.

Dentro una stessa tassonomia i termini sono in OR, fra tassonomie diverse in AND: per questo il
layer dei percorsi accessibili prende tutta la scala, e vale come «ha una difficoltà di
accessibilità, qualunque sia». «Accessibile» non è un'attività né un campo a sé.

- **l'import scrive le attività**: tipo del nodo Drupal, termini di `field_tipologia_sentiero`
  (da cui `accessible-trails`) e `sardegnasentieri:cammino` per gli itinerari con il termine 4728
  di `field_tipologia_itinerario`. Le scrive con un solo `sync()` **senza eventi sul pivot**,
  quindi gli observer del package non accodano job per traccia;
- **le relazioni layer→termine le scrive `sardegnasentieri:configure-layers`**: trova i layer per nome
  italiano esatto fra quelli dell'app di import, salta con un avvertimento quelli che su
  quell'ambiente non esistono, e fallisce senza scrivere nulla solo se mancano i termini. Poi
  accoda da sé il ricalcolo dei layer e il reindex, salvo `--no-recalc`. **Lo chiama l'import con
  `--reset`**, prima del batch: dove il reset notturno è attivo non va lanciato a mano, altrove
  una volta dopo il deploy (oc:8910);
- **a fine batch dell'import** la catena è `SyncTaxonomyWhereJob` →
  `ResolveSardegnaSentieriRelationsJob` → `RecalculateSardegnaSentieriLayersJob` →
  `CalculateNearbyTracksJob`, che a fine calcolo dei sentieri correlati accoda
  `RegenerateTaxonomyWhereOutputsJob` (reindex e file AWS, oc:8959).
  `RecalculateSardegnaSentieriLayersJob` ricalcola i layer automatici dell'app che hanno attività o aree: `layerables` con
  `assignTracksByTaxonomy()`, POI solo sui layer delle aree, `properties.layers` allineato in modo
  sincrono, geometria e PBF una volta per layer e solo se qualcosa è cambiato;
- **il reset notturno** non tronca più `taxonomy_activities`: le attività restano con il loro id,
  e con loro le relazioni dei layer; cancella a mano le righe di tracce e POI in
  `taxonomy_activityables` e `layerables`, che dopo `RESTART IDENTITY` indicherebbero altri record.
  Toglie anche i legami di tracce e POI in `taxonomy_termables` (i termini restano): l'import li
  riscrive.

Il filtro per area non ricalcola la geometria: legge `properties->taxonomy_where`, scritto da
`GeometryComputationService::syncTaxonomyWhere()` del package. Una traccia esce se nel suo
`taxonomy_where` c'è la chiave dell'area (l'id della `TaxonomyWhere`) **e** quella voce ha
sorgente `geohub_conf_32`, letta sia come `_source` (forma attuale, oc:8588) sia come `source`
(forma di oc:8487).

Vincoli:

- **è affidabile quanto il calcolo delle aree**: una traccia mai ricalcolata, o spostata dopo
  l'ultimo calcolo, porta le aree vecchie. Il ricalcolo completo mantiene il vecchio
  `taxonomy_where` solo se la traccia non interseca più nessuna `TaxonomyWhere`; se ne interseca
  altre, lo sostituisce per intero;
- **una voce sola per chiave**: il package usa come chiave
  `COALESCE(osmfeatures_id, osm2cai_id, id)`, quindi una `TaxonomyWhere` osm2cai con `osm2cai_id`
  uguale all'id di un'area può prendere il posto della voce dell'area su una traccia che le
  attraversa entrambe. Il controllo della sorgente evita i falsi positivi, non questa perdita;
- **un layer di tipologia senza termini resta vuoto o vecchio**: il job salta i layer senza
  filtro. Succede finché `configure-layers` non è stato lanciato;
- **svuotare `taxonomy_terms` svuota anche i layer**: il pivot `taxonomy_termables` ha
  `cascadeOnDelete` sul termine, quindi un `configure-taxonomy-terms --fresh` porta via relazioni
  che con i termini ricreati non tornano, perché gli id sono nuovi. Per questo l'import non passa
  più `--fresh`: era il motivo per cui ogni notte le mappe dei layer si svuotavano (oc:8910);
- **un errore sugli effetti di un layer non si ripete da solo**: è nel log `import`; se
  `properties.layers` è già allineato il giro dopo non riaccoda PBF e geometria;
- **una traccia senza aree catastali nel suo `taxonomy_where` non esce dal filtro per area**: succede
  quando `SyncModelTaxonomyWhereJob` del package non trova aree con il calcolo locale e ripiega su
  osmfeatures, che scrive solo aree amministrative (chiavi `R…` con `_admin_level`). Dopo un import
  le aree le ricalcola il batch: vedi [import-asincrono-e-anomalie.md](import-asincrono-e-anomalie.md)
  (oc:8880).
  `wm:resync-taxonomy-where --only-legacy` non le prende, perché hanno `_admin_level`. In locale il
  27/10 era una traccia su 779 (oc:8875).

## Perché così

- **I filtri leggono i termini della traccia, non i layer** (oc:8988): il filtro per layer era un
  passaggio indiretto — i layer si costruiscono dalle tassonomie — e aveva già dato risultati
  sbagliati e sovrapposizioni (oc:8827). Allo scrum del 16/11 Giuseppe: «non voglio più mettere un
  filtro per layer. Ok, c'abbiamo ora le tassonomie, usiamo quelle».
- **Un solo filtro generico nel package, richiamato una volta per tassonomia** (oc:8988): con la
  tabella unica `taxonomy_terms` lo stesso codice copre tutte le tassonomie (scrum 16/11: «il
  filtro deve essere uno che funziona per tutti»), e il selettore è lo stesso del form.
- **Percorribilità e Stato validazione separati, a scelta singola** (oc:8988): il cliente li aveva
  chiesti separati in oc:8827 (28/10), e con oc:8989 la percorribilità è una famiglia a sé. Allo
  scrum del 17/11 Giuseppe: «tutti e due i filtri mettili single choice […] poi se c'è da
  cambiare lo cambiamo».
- **Niente filtro Ruolo** (oc:8988): al cliente non serviva (oc:8827, 02/11: «Continuo a non
  capire l'utilità del filtro "Ruoli"»). Allo scrum del 17/11 Giuseppe ha detto che sarebbe più
  utile sugli enti, ma per ora va tolto.
- **Aree catastali sul selettore di `Choice`** (oc:8988): alla call del 16/11 Giuseppe, sul
  componente outline: «smettiamo di usarlo, cioè là dove c'è da fare una select si usa il choice
  field». Le aree non sono termini di `taxonomy_terms`, quindi estendono il filtro di base del
  package (`ChoiceFilter`) invece di `TaxonomyTermFilter`, con la stessa chiave del vecchio filtro
  per non rompere i link salvati. Il segnaposto della ricerca è quello degli altri filtri.
- **Aree a scelta multipla, in OR, e «passa per l'area» anche per un tratto breve** (oc:8806):
  review del 26/10. Il cliente chiede «i cammini delle aree B, G e T»; il criterio geometrico è
  l'unico valido anche per cammini e itinerari, che non hanno codice catastale.
- **Ricerca invece delle checkbox** (oc:8825): richiesta di Giuseppe Bonfanti allo scrum del 27/10.
  Le checkbox occupano spazio in proporzione al numero di aree, e un filtro per province ne
  aggiungerebbe altre.
- **Si legge la sorgente della voce, non solo la chiave** (oc:8806): la chiave da sola collide con
  gli `osm2cai_id` (in locale ZNUB2 ha `osm2cai_id = 81`, l'id di Area T).
- **Il reset riconfigura i layer da sé** (oc:8910): è l'import con `--reset` a chiamare
  `configure-layers`, prima del batch. Il passo manuale dopo il reset notturno non c'è più, ed era
  l'unico modo di rimediare ai layer rimasti senza filtri.
- **Solo il termine «Cammini di Sardegna» di `tipologia_itinerari`** (oc:8827): gli altri 8
  termini non servono a nessun layer oggi.
- **Un cammino resta anche un itinerario** (oc:8827): è così anche in Drupal; Itinerari conta i
  cammini.
- **Il reset conserva le attività** (oc:8827): con il `TRUNCATE ... CASCADE` le relazioni
  layer→attività sparivano ogni notte, e la configurazione dei layer andava rifatta.
- **Ricalcolo a fine batch, non job per traccia** (oc:8827): gli observer del package, con il
  reset, avrebbero accodato per ogni riga una catena PBF del layer intero, e per ogni traccia un
  ricalcolo con 5 minuti di attesa sulla coda `default`. Il job sta prima del reindex perché il
  reindex legge `properties.layers`. Deciso allo scrum del 29/10: «l'associazione la buttiamo giù e
  se la ricrea».
- **Configurazione con un comando idempotente** (oc:8827): gli ambienti sono tre, e il comando la
  rende ripetibile su un database nuovo.
- **Niente filtro App sulla lista EC Tracce** (oc:8875): forestas ha una sola app, e il filtro
  restituiva sempre tutte le tracce. Si toglie in forestas perché il package è condiviso.
- **Niente Include/Exclude per id** (oc:8875): servivano al vecchio campo che associa le tracce ai
  layer, riscritto a settembre 2025 con rotte proprie (`ee8a5551` nel package): nessuno li usa
  più, e nel menu comparivano solo come titolo, senza nessun controllo.

## Come ci siamo arrivati

- **Le tipologie restano attività** (oc:8827, **superata da oc:8910**): la tassonomia dedicata era
  stata proposta allo scrum del 29/10 e rimandata a un ticket a parte, che è oc:8910. Oggi
  «Tipo percorso» esiste e i layer si legano ai suoi termini.
- **Filtro «Layer» sulle tipologie** (oc:8806 e oc:8825, superato da oc:8988):
  `EcTrackLayerFilter`, con la stessa chiave del package (`features_by_layer_ecTracks`), elencava
  solo i layer non legati a un'area catastale e restituiva le tracce del layer con una regola a
  quattro passi: layer manuale → tracce associate in `layerables`; automatico con attività o aree →
  `onLayer()`; automatico senza filtro ma con tracce associate → le associate; altrimenti
  `onLayer()`. Sostituiva il filtro del package invece di affiancarlo, perché con due filtri sulla
  stessa chiave Nova applica il primo che trova. Abbandonato perché il risultato dipendeva da come
  il layer era configurato e non da cosa c'è scritto sulla traccia.
- **Due filtri custom in Forestas, nessuna modifica al package** (oc:8806, superata da oc:8988):
  deciso allo scrum del 21/10. Oggi il filtro generico sta nel package, e forestas lo richiama.
- **Multiselect di `outl1ne/nova-multiselect-filter` per le aree** (oc:8825, superato da oc:8988):
  scelto perché la multiselect nativa di Nova è una `<select multiple>` senza ricerca, in cui si
  sceglie più di una voce solo con Cmd/Ctrl+clic, e `outl1ne/nova-multiselect-field` è un campo e
  non offre un filtro. Il pacchetto non aveva l'italiano, e i suoi testi stavano in
  `lang/vendor/nova-multiselect-filter/it.json`. Tolto con il passaggio al selettore di `Choice`.
- **Filtro «Ruolo»** (oc:8875, superato da oc:8988): `EcTrackRuoloFilter`, tracce con almeno un
  ente in quel ruolo (`enteables`). Tolto perché al cliente non serviva.
- **Filtro «Stato validazione» con l'identifier scritto nel codice** (oc:8932, superato da
  oc:8988): `EcTrackValidationStatusFilter` proponeva solo gli stati dichiarati dal comando dei
  termini, che oggi stanno in `EcTrack::validationStatusTerms()` per il campo del form.
- **I layer con un filtro automatico restano su `onLayer()`** (oc:8825, superata da oc:8988 insieme
  al filtro dei layer): il filtro di Nova li leggeva dal filtro e non da `layerables`, che si
  aggiorna solo a fine batch.
- **Il filtro dei layer esclude solo i layer legati a un'area `geohub_conf_32`** (oc:8806, superata
  da oc:8988): un layer legato a una `TaxonomyWhere` di altra sorgente restava una tipologia.
- **Si corregge il filtro, non la configurazione dei layer** (oc:8825, superata da oc:8827):
  un'attività «cammino» non esisteva e la configurazione andava ripetuta su ogni ambiente; Cammini
  e Sentieri accessibili mostravano le righe di `layerables`. Su UAT il 30/10 erano righe del 9/9
  su id che dopo i reset indicavano altre tracce: Sentieri e Cammini avevano le stesse 597. Ora
  l'attività c'è e la configurazione la applica un comando.
- **Filtro dei layer con `apply()` del package** (oc:8806, superato da oc:8825): `onLayer()`
  restituisce le tracce candidate per attività e aree del layer; «Cammini di Sardegna» e «Sentieri
  accessibili», senza né l'una né l'altra, davano tutte le tracce (779 in locale), anche combinati
  con un'area. Il cliente l'aveva segnalato su UAT (oc:8827).
- **Tracce associate per tutti i layer, poi `onLayer()` se non ce ne sono** (oc:8825, superata):
  era la prima regola; avrebbe spostato anche Sentieri e Itinerari sulla copia in `layerables`,
  aggiornata in differita.
- **Area a scelta singola** (oc:8806, superata): era la proposta dello scrum del 21/10; nella
  review del 26/10 si è passati alla scelta multipla perché il cliente combina più aree.
- **Checkbox (`BooleanFilter` di Nova)** (oc:8806, superata da oc:8825): davano la scelta multipla
  ma una casella per area, tutte sempre aperte nel menu dei filtri.
- **Codice catastale come criterio dell'area** (oc:8806, scartata): vale solo per i sentieri;
  cammini e itinerari non hanno codice. Sui dati locali del 21/10 coincideva con l'intersezione
  su 394 tracce su 404.
