# Design: Tab "Forestas" su EcPoi e EcTrack in Nova

**Data:** 2026-03-30
**Riferimento pattern:** [osm2cai2 SiHikingRoute](https://github.com/webmappsrl/osm2cai2/blob/main/app/Nova/SiHikingRoute.php)

## Obiettivo

Aggiungere una tab "Forestas" nelle risorse Nova `EcPoi` e `EcTrack` che esponga il contenuto di `properties->forestas` campo per campo, editabile dall'utente.

I dati vengono scritti dall'import `sardegnasentieri:import`. In questa fase il sovrascrittura da import è accettata (l'import verrà lanciato una volta sola in produzione).

## Modifica Import

I campi array in `ForestasPoiData` e `ForestasTrackData` sono attualmente `list<mixed>` (array PHP indicizzati numericamente). Il campo Nova `KeyValue` richiede array associativi. Si converte in `toArray()` ogni lista in array associativo indicizzato (`{"0": "val", "1": "val"}`).

**Campi interessati:**
- `ForestasPoiData`: `collegamenti`, `zona_geografica`
- `ForestasTrackData`: `allegati`, `video`, `gpx`, `zona_geografica`

## Tab EcPoi

Metodo `getForestasTabFields()` in `App\Nova\EcPoi`:

| Label | Tipo Nova | Attributo |
|---|---|---|
| Codice | `Text` | `properties->forestas->codice` |
| Come arrivare | `Textarea` | `properties->forestas->come_arrivare` |
| URL | `Text` | `properties->forestas->url` |
| Aggiornato il | `Text` | `properties->forestas->updated_at` |
| Collegamenti | `KeyValue` | `properties->forestas->collegamenti` |
| Zona geografica | `KeyValue` | `properties->forestas->zona_geografica` |

## Tab EcTrack

Metodo `getForestasTabFields()` in `App\Nova\EcTrack`:

| Label | Tipo Nova | Attributo |
|---|---|---|
| Source ID | `Text` | `properties->forestas->source_id` |
| Tipo | `Text` | `properties->forestas->type` |
| URL | `Text` | `properties->forestas->url` |
| Aggiornato il | `Text` | `properties->forestas->updated_at` |
| Allegati | `KeyValue` | `properties->forestas->allegati` |
| Video | `KeyValue` | `properties->forestas->video` |
| GPX | `KeyValue` | `properties->forestas->gpx` |
| Zona geografica | `KeyValue` | `properties->forestas->zona_geografica` |

## File Modificati

1. `app/Nova/EcPoi.php` — aggiunge `fields()` con tab Forestas
2. `app/Nova/EcTrack.php` — aggiunge `getForestasTabFields()` + tab nel `fields()`
3. `app/Dto/Import/ForestasPoiData.php` — converte liste in array associativi in `toArray()`
4. `app/Dto/Import/ForestasTrackData.php` — stesso
