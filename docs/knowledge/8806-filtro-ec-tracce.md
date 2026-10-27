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

Sui dati locali del 26/10 Sentieri e Itinerari passano dal punto 2 (614 e 165 tracce), Cammini di
Sardegna e Sentieri accessibili dal punto 3 (50 e 2).

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
- **il filtro dei layer mostra le associazioni come sono**: fra le 50 tracce locali di «Cammini
  di Sardegna» c'è almeno un sentiero (G 126). Si correggono nel layer, in modalità manuale;
- **il reset notturno non ripulisce `layerables`**: `TRUNCATE TABLE ec_tracks RESTART IDENTITY
  CASCADE` non la tocca, perché non ha chiavi esterne verso `ec_tracks`, e le associazioni
  manuali possono restare su id che ora indicano altre tracce.
  Follow-up non ancora aperto (oc:8825);
- **una traccia senza aree catastali nel suo `taxonomy_where` non esce dal filtro per area**: succede
  quando `SyncModelTaxonomyWhereJob` del package non trova aree con il calcolo locale e ripiega su
  osmfeatures, che scrive solo aree amministrative (chiavi `R…` con `_admin_level`).
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
- **Si corregge il filtro, non la configurazione dei layer** (oc:8825): un'attività «cammino» non
  esiste, e la configurazione andrebbe ripetuta su ogni ambiente. Per l'app le tracce di un layer
  sono quelle associate (`LayerService::getRelatedModels()`).
- **I layer con un filtro automatico restano su `onLayer()`** (oc:8825): per loro `layerables` è
  una copia del filtro, aggiornata da `SyncAutoLayerAfterTrackTaxonomyChangeJob` con almeno 5
  minuti di attesa sulla coda `default`; leggerla darebbe risultati vecchi dopo un import.
- **Niente filtro App sulla lista EC Tracce** (oc:8875): forestas ha una sola app, e il filtro
  restituiva sempre tutte le tracce. Si toglie in forestas perché il package è condiviso.
- **Niente Include/Exclude per id** (oc:8875): servivano al vecchio campo che associa le tracce ai
  layer, riscritto a settembre 2025 con rotte proprie (`ee8a5551` nel package): nessuno li usa
  più, e nel menu comparivano solo come titolo, senza nessun controllo.

## Come ci siamo arrivati

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
