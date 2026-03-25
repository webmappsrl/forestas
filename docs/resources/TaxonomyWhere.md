# TaxonomyWhere (app-level customization)

> Last updated: 2026-03-25

## Overview

Documentazione delle sole personalizzazioni apportate a livello di progetto Forestas alla risorsa e al modello `TaxonomyWhere` del wm-package.

Per la documentazione completa della classe base, vedere:
[`TaxonomyWhere (wm-package)`](https://github.com/webmappsrl/wm-package/blob/main/docs/resources/TaxonomyWhere.md)

---

## Eloquent Model

### Override

**Nessun override.** Non esiste `app/Models/TaxonomyWhere.php`. Il modello usato direttamente è `Wm\WmPackage\Models\TaxonomyWhere`.

---

## Nova Resource

### Classe e namespace

`App\Nova\TaxonomyWhere` — `app/Nova/TaxonomyWhere.php`

### Parent Class

Estende `Wm\WmPackage\Nova\TaxonomyWhere`.

### Customizzazioni rispetto al parent

#### Actions aggiuntive

| Azione | Classe | Standalone | Descrizione |
|--------|--------|------------|-------------|
| Import Aree Catastali | `App\Nova\Actions\ImportTaxonomyWhereFromLayersAction` | Si | Importa aree catastali da Geohub conf/32 tramite `App\Services\Import\TaxonomyWhereLayersImportService`. Aggiunto in coda a `parent::actions($request)` con spread operator. |

Tutte le altre azioni (Import TaxonomyWhere, Crea Layer, Ricarica Geometry, Sincronizza Tracks) sono ereditate invariate dal wm-package.

#### Fields, Filters, Lenses, Metrics

Nessuna modifica rispetto al parent.

---

## Behaviors & Business Logic

### Import Aree Catastali (forestas-specifico)

`TaxonomyWhereLayersImportService` scarica:

1. Un GeoJSON da `https://geohub.webmapp.it/api/export/taxonomy/geojson/32/AreeCatastaliconLayers.geojson`
2. Una configurazione layer da `https://wmfe.s3.eu-central-1.amazonaws.com/geohub/conf/32.json`

Incrocia `layer_id` dal GeoJSON con i layer nella conf per costruire nome e properties. La geometria viene inserita con `ST_GeomFromGeoJSON()` in modo sincrono (a differenza degli import OSMFeatures e OSM2CAI che usano job asincroni). La sorgente è identificata dal valore `'geohub_conf_32'` in `properties['source']`.

#### Chiavi `properties` specifiche di questa sorgente

| Chiave | Tipo | Descrizione |
|--------|------|-------------|
| `source` | string | Sempre `'geohub_conf_32'` per questa sorgente |
| `external_layer_id` | string | ID layer Geohub |
| `layer_id` | string | Alias backward-compatible di `external_layer_id` |
| `title` | string/array | Titolo localizzato del layer |
| `subtitle` | string/array | Sottotitolo del layer |
| `description` | string/array | Descrizione del layer |
| `feature_image` | string (URL) | URL immagine copertina |
| `layer_name` | string | Nome interno layer Geohub |

> **Nota:** `TaxonomyWhereSourceFilter` del wm-package non include `'geohub_conf_32'` tra le opzioni. I record importati da questa sorgente non sono filtrabili tramite il filtro "Sorgente" standard.

---

## Nova Menu

Sezione: **Taxonomies** (icona `document`)

Posizione nel menu:
```
Dashboard
Admin (solo Administrator)
UGC
EC
Taxonomies  <-- qui
  - TaxonomyPoiType
  - TaxonomyActivity
  - TaxonomyWhere
Files (solo Administrator)
```

---

## Related Services

| Service | Namespace | Utilizzo |
|---------|-----------|---------|
| `TaxonomyWhereLayersImportService` | `App\Services\Import` | Import aree catastali da Geohub conf/32 |

---

## Related Commands

Nessun comando Artisan dedicato. L'import avviene esclusivamente tramite le action Nova.

---

## Notes

- La sorgente `geohub_conf_32` è specifica di questo progetto e non è gestita dal wm-package. Se la struttura del GeoJSON o della conf Geohub cambia, aggiornare `TaxonomyWhereLayersImportService`.
- A differenza delle sorgenti OSMFeatures/OSM2CAI, la geometria viene inserita in modo sincrono durante l'import da Geohub conf/32. Non c'è job di recupero geometry per questa sorgente, quindi `RetryTaxonomyWhereGeometryFetch` non ha effetto sui record `geohub_conf_32`.
