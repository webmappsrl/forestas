# Filtri della lista EC Tracce

## Come funziona oggi

Nella lista Nova delle EC Tracce (anche nei pannelli delle tracce del dettaglio Ente) il menu dei
filtri ha quattro voci: **Layer**, **Aree catastali**, **Ruolo** e **Stato validazione** (oc:8875).
I primi due sostituiscono il filtro dei layer del package:

- **«Layer»** (`app/Nova/Filters/EcTrackLayerFilter.php`): stessa chiave del package
  (`features_by_layer_ecTracks`), ma elenca solo i layer **non** legati a un'area catastale, cioè
  le tipologie (Sentieri, Itinerari, Cammini di Sardegna, Sentieri accessibili);
- **«Aree catastali»** (`app/Nova/Filters/EcTrackCadastralAreaFilter.php`): multiselect con
  ricerca (`MultiselectFilter` di `outl1ne/nova-multiselect-filter`) con le `TaxonomyWhere` di
  sorgente `geohub_conf_32` (`TaxonomyWhereLayersImportService::SOURCE_KEY`). Il valore è la lista
  degli id delle aree scelte; le aree valgono in OR; i due filtri si combinano in AND. Il pacchetto
  non ha l'italiano: i testi del componente sono in `lang/vendor/nova-multiselect-filter/it.json`.

Gli altri due:

- **«Ruolo»** (`app/Nova/Filters/EcTrackRuoloFilter.php`): tracce con almeno un ente in quel ruolo
  (tabella `enteables`, scritta dall'import di Sardegna Sentieri); le tracce senza enti non hanno
  un valore che le trovi;
- **«Stato validazione»**: filtro del campo `Select` `stato_validazione`; le tracce senza stato non
  hanno un valore che le trovi.

Non ci sono il filtro **App** (il campo del package è reso non filtrabile in
`App\Nova\EcTrack::fields()`; colonna, dettaglio e form restano, e sulle altre liste il filtro
c'è ancora) né **Include/Exclude per id** (scartati in `App\Nova\EcTrack::filters()`).

`App\Nova\EcTrack::filters()` **sostituisce** il filtro del package invece di aggiungerne uno:
con due filtri sulla stessa chiave Nova applicherebbe il primo che trova
(`vendor/laravel/nova/src/Filters/FilterDecoder.php:43-45`).

Il filtro dei layer restituisce le tracce **del layer**, non le candidate di `onLayer()`
(oc:8825):

1. layer in modalità manuale (`Layer::isAutoTrackMode()` falso) → le tracce associate
   (tabella `layerables`);
2. layer in automatico con un'attività o aree in `properties.taxonomy_where` → `onLayer()`;
3. layer in automatico senza filtro ma con tracce associate → le tracce associate;
4. altrimenti → `onLayer()`.

Con i layer configurati (sotto) tutti e quattro i layer di tipologia passano dal punto 2.

### Come le tracce finiscono nei layer (oc:8827)

Un layer di tipologia è definito da un'attività:

| Layer | Attività |
|---|---|
| Sentieri | `sardegnasentieri:type:sentiero` |
| Itinerari | `sardegnasentieri:type:itinerario` (cammini compresi) |
| Cammini di Sardegna | `sardegnasentieri:cammino` |
| Sentieri Accessibili | `accessible-trails` |

- **l'import scrive le attività**: tipo del nodo Drupal, termini di `field_tipologia_sentiero`
  (da cui `accessible-trails`) e `sardegnasentieri:cammino` per gli itinerari con il termine 4728
  di `field_tipologia_itinerario`. Le scrive con un solo `sync()` **senza eventi sul pivot**,
  quindi gli observer del package non accodano job per traccia;
- **i legami layer→attività li scrive `sardegnasentieri:configure-layers`**, una volta per
  ambiente: trova i layer per nome italiano esatto fra quelli dell'app di import e si ferma senza
  scrivere se un layer o un'attività manca o è ambiguo. Poi accoda da sé il ricalcolo dei layer e
  il reindex: non serve lanciarli a mano;
- **a fine batch dell'import** la catena è `SyncTaxonomyWhereJob` →
  `RecalculateSardegnaSentieriLayersJob` → `RegenerateTaxonomyWhereOutputsJob` (reindex e file
  AWS). Il job ricalcola i layer automatici dell'app che hanno attività o aree: `layerables` con
  `assignTracksByTaxonomy()`, POI solo sui layer delle aree, `properties.layers` allineato in modo
  sincrono, geometria e PBF una volta per layer e solo se qualcosa è cambiato;
- **il reset notturno** non tronca più `taxonomy_activities`: le attività restano con il loro id,
  e con loro i legami dei layer; cancella a mano le righe di tracce e POI in
  `taxonomy_activityables` e `layerables`, che dopo `RESTART IDENTITY` indicherebbero altri record.

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
- **un layer di tipologia senza attività resta vuoto o vecchio**: il job salta i layer senza
  filtro. Succede finché `configure-layers` non è stato lanciato, e dopo il comando manuale
  `sardegnasentieri:reset`, che cancella le attività `sardegnasentieri:*` con i loro legami;
- **l'attività dei cammini la crea solo un reimport completo**: l'import orario reimporta solo i
  tracciati cambiati in Drupal; serve `sardegnasentieri:import --force` (o il reset notturno)
  prima di `configure-layers`;
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

- **Due filtri custom in Forestas, nessuna modifica al package** (oc:8806): deciso allo scrum del
  21/10.
- **Aree a scelta multipla, in OR, e «passa per l'area» anche per un tratto breve** (oc:8806):
  review del 26/10. Il cliente chiede «i cammini delle aree B, G e T»; il criterio geometrico è
  l'unico valido anche per cammini e itinerari, che non hanno codice catastale.
- **Multiselect con ricerca invece delle checkbox** (oc:8825): richiesta di Giuseppe Bonfanti
  allo scrum del 27/10. Le checkbox occupano spazio in proporzione al numero di aree, e un filtro
  per province ne aggiungerebbe altre.
- **Il pacchetto e non la multiselect nativa di Nova** (oc:8825): la nativa (`MultiSelectFilter`,
  da un campo `MultiSelect` filtrabile) è una `<select multiple>` senza ricerca, in cui si sceglie
  più di una voce solo con Cmd/Ctrl+clic. `outl1ne/nova-multiselect-field`, già nel progetto, è
  un campo e non offre un filtro.
- **Si legge la sorgente della voce, non solo la chiave** (oc:8806): la chiave da sola collide con
  gli `osm2cai_id` (in locale ZNUB2 ha `osm2cai_id = 81`, l'id di Area T).
- **Il filtro dei layer esclude solo i layer legati a un'area `geohub_conf_32`** (oc:8806): un
  layer legato a una `TaxonomyWhere` di altra sorgente resta una tipologia.
- **Le tipologie restano attività** (oc:8827): una tassonomia dedicata «Tipologia percorso» è
  stata proposta allo scrum del 29/10 e rimandata a un ticket a parte.
- **Solo il termine «Cammini di Sardegna» di `tipologia_itinerari`** (oc:8827): gli altri 8
  termini non servono a nessun layer oggi.
- **Un cammino resta anche un itinerario** (oc:8827): è così anche in Drupal; Itinerari conta i
  cammini.
- **Il reset conserva le attività** (oc:8827): con il `TRUNCATE ... CASCADE` i legami
  layer→attività sparivano ogni notte, e la configurazione dei layer andava rifatta.
- **Ricalcolo a fine batch, non job per traccia** (oc:8827): gli observer del package, con il
  reset, avrebbero accodato per ogni riga una catena PBF del layer intero, e per ogni traccia un
  ricalcolo con 5 minuti di attesa sulla coda `default`. Il job sta prima del reindex perché il
  reindex legge `properties.layers`. Deciso allo scrum del 29/10: «l'associazione la buttiamo giù e
  se la ricrea».
- **Configurazione con un comando idempotente** (oc:8827): gli ambienti sono tre, e il comando la
  rende ripetibile su un database nuovo.
- **I layer con un filtro automatico restano su `onLayer()`** (oc:8825): il filtro di Nova li
  legge dal filtro e non da `layerables`, che si aggiorna solo a fine batch.
- **Niente filtro App sulla lista EC Tracce** (oc:8875): forestas ha una sola app, e il filtro
  restituiva sempre tutte le tracce. Si toglie in forestas perché il package è condiviso.
- **Niente Include/Exclude per id** (oc:8875): servivano al vecchio campo che associa le tracce ai
  layer, riscritto a settembre 2025 con rotte proprie (`ee8a5551` nel package): nessuno li usa
  più, e nel menu comparivano solo come titolo, senza nessun controllo.

## Come ci siamo arrivati

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
- **Area a scelta singola, con una tendina** (oc:8806, superata): era la proposta dello scrum del
  21/10; nella review del 26/10 si è passati alla scelta multipla perché il cliente combina più
  aree.
- **Checkbox (`BooleanFilter` di Nova)** (oc:8806, superata da oc:8825): davano la scelta multipla
  ma una casella per area, tutte sempre aperte nel menu dei filtri.
- **Codice catastale come criterio dell'area** (oc:8806, scartata): vale solo per i sentieri;
  cammini e itinerari non hanno codice. Sui dati locali del 21/10 coincideva con l'intersezione
  su 394 tracce su 404.
