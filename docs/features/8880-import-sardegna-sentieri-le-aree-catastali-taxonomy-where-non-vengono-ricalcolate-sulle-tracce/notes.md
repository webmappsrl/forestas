> Ticket: oc:8880

# Notes — Aree catastali a fine import Sardegna Sentieri

## Deviazioni dal piano

Nessuna deviazione rilevante. Nei test, oltre all'helper `assertCatenaAree()` previsto dal piano,
c'è `importaEChiudiBatch()`, che lancia il comando e invoca la callback `finally`: evita di ripetere
lo stesso blocco nei quattro test.

## Bug trovati

- **Elasticsearch locale non indicizzava** (ambiente, non codice): con il disco della VM Docker al
  91%, oltre la soglia `watermark.high` del 90%, Elasticsearch non assegnava lo shard del nuovo
  indice in scrittura `ec_tracks_…`, e ogni `searchable()` falliva con `Bulk update error`. Per la
  prova le soglie sono state alzate in modo transient (fino al riavvio di Elasticsearch); il
  rimedio vero è liberare spazio nella VM Docker.

## Decisioni

- **Il ricalcolo dei layer legati alle aree resta fuori**: l'import fa quello che fa l'action
  `SyncEcTaxonomyWhereAction`, più il reindex. I layer sono nel ticket separato deciso allo scrum
  del 27/10.
- **Reindex in catena** (decisione presa dopo la challenge): il solo sync non aggiorna
  Elasticsearch, che legge `taxonomyWheres` da `properties.taxonomy_where`.

## Prova locale (28/10)

`sardegnasentieri:import --force` (1742 job) con `taxonomy_where` tolto a mano da tutte le tracce:
a fine batch, log `Sync aree catastali e reindex accodati.`; `SyncTaxonomyWhereJob` completato in
32 s, poi `RegenerateTaxonomyWhereOutputsJob` in 4 s, in quest'ordine. Risultato: 780 tracce su 780
con `taxonomy_where` nel DB; in Elasticsearch 779 documenti su 780 con `taxonomyWheres`
valorizzato (es. traccia 1: «Area D (Sud-OVEST)», «Sardegna», «Oristano», …).

Un import senza novità non apre il batch e quindi non lancia la catena: è il ramo «nessun job»,
fuori scope perché non è cambiato nulla.

## Follow-up

- Su UAT, dopo il reset del mattino: tracce con `taxonomy_where` > 0, filtro «Aree catastali» con
  risultati, documenti Elasticsearch delle tracce con `taxonomyWheres` valorizzato.
