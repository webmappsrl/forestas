---
name: TaxonomyWhere architecture
description: Gerarchia modello, pattern geometria asincrona PostGIS, sorgenti dati, properties consolidate
type: project
---

TaxonomyWhere estende Taxonomy > Polygon > GeometryModel. Nessun override in app/Models/.

Geometria PostGIS (geography multipolygon) nullable — popolata in background da:
- FetchTaxonomyWhereGeometryJob (OSMFeatures, via osmfeatures_id in properties)
- FetchOsm2caiSectorGeometryJob (OSM2CAI, via osm2cai_id in properties)
Entrambi usano ST_GeomFromGeoJSON() via raw SQL. 3 tentativi, backoff 60s.

Sorgenti: 'osmfeatures', 'osm2cai', 'geohub_conf_32' (forestas-specifico).
La sorgente è identificata da properties['source'].

Migrazione 2026-03-25: osmfeatures_id e admin_level erano colonne dedicate, ora sono in properties JSONB. Esiste indice parziale su (properties->>'osmfeatures_id').

Post-import ogni sorgente chiama GeometryComputationService::syncTracksTaxonomyWhere().

**Why:** geometria può non essere disponibile subito dall'API — i job consentono retry asincroni.
**How to apply:** quando si cerca un record per osmfeatures_id usare whereRaw("properties->>'osmfeatures_id' = ?"), non colonne dirette.
