# Filtri delle EC Tracce: tipologie e aree catastali

## Come funziona oggi

Nella lista Nova delle EC Tracce (anche nei pannelli delle tracce del dettaglio Ente) ci sono due
filtri al posto del filtro dei layer del package:

- **filtro dei layer** (`app/Nova/Filters/EcTrackLayerFilter.php`): stessa etichetta e stessa
  chiave del package (`features_by_layer_ecTracks`), ma elenca solo i layer **non** legati a
  un'area catastale, cioè le tipologie (Sentieri, Itinerari, Cammini di Sardegna, Sentieri
  accessibili);
- **«Aree catastali»** (`app/Nova/Filters/EcTrackCadastralAreaFilter.php`): checkbox con le
  `TaxonomyWhere` di sorgente `geohub_conf_32`
  (`TaxonomyWhereLayersImportService::SOURCE_KEY`). Le aree spuntate valgono in OR; i due filtri
  si combinano in AND.

`App\Nova\EcTrack::filters()` **sostituisce** il filtro del package invece di aggiungerne uno:
con due filtri sulla stessa chiave Nova applicherebbe il primo che trova
(`vendor/laravel/nova/src/Filters/FilterDecoder.php:43-45`).

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
- **«Cammini di Sardegna» e «Sentieri accessibili» restituiscono tutte le tracce** nel filtro dei
  layer, perché `onLayer()` seleziona per aree e attività del layer e quei due layer, sui dati
  locali del 26/10, non hanno né l'una né l'altra: combinati con un'area danno tutte le tracce
  dell'area (oc:8825).

## Perché così

- **Due filtri custom in Forestas, nessuna modifica al package** (oc:8806): deciso allo scrum del
  21/10.
- **Aree a scelta multipla, in OR, e «passa per l'area» anche per un tratto breve** (oc:8806):
  review del 26/10. Il cliente chiede «i cammini delle aree B, G e T»; il criterio geometrico è
  l'unico valido anche per cammini e itinerari, che non hanno codice catastale.
- **Si legge la sorgente della voce, non solo la chiave** (oc:8806): la chiave da sola collide con
  gli `osm2cai_id` (in locale ZNUB2 ha `osm2cai_id = 81`, l'id di Area T).
- **Il filtro dei layer esclude solo i layer legati a un'area `geohub_conf_32`** (oc:8806): un
  layer legato a una `TaxonomyWhere` di altra sorgente resta una tipologia.

## Come ci siamo arrivati

- **Area a scelta singola, con una tendina** (oc:8806, superata): era la proposta dello scrum del
  21/10; nella review del 26/10 si è passati alle checkbox perché il cliente combina più aree.
- **Codice catastale come criterio dell'area** (oc:8806, scartata): vale solo per i sentieri;
  cammini e itinerari non hanno codice. Sui dati locali del 21/10 coincideva con l'intersezione
  su 394 tracce su 404.
