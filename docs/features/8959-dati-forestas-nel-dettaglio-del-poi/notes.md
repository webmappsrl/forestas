> Ticket: oc:8959

# Notes — dati di Forestas nel dettaglio del POI

## Divergenze dal piano, task per task

### Task 2: percorsi collegati al POI

Il piano è passato per tre versioni, tutte decise col dev il 12/11:
1. tabella nuova `ec_poi_nearby_tracks` entro 5 km (scartata dal dev: niente tabelle nuove);
2. i collegamenti di Drupal in `ec_poi_ec_track`, ordinati per distanza;
3. **`ec_poi_ec_track` calcolata per vicinanza entro 1 km** (versione finale), come i sentieri correlati
   fra tracce. Misure in locale, collegamenti / per traccia / POI senza percorsi: 50 m 1.661 / 2,2 /
   179; 300 m 2.489 / 3,2 / 52; 1 km 4.283 / 5,6 / 18; Drupal 2.452 / 3,2. Il 5 km del wireframe
   avrebbe dato 14.018 collegamenti. Metà dei collegamenti di Drupal sta entro 103 m, un quarto
   oltre 1 km.

Quello che ne segue:
- `CalculatePoiTracksChunkJob` nel batch di `CalculateNearbyTracksJob`; ogni blocco riscrive solo i
  suoi POI in transazione (il dettaglio del POI legge dal vivo: mai vuoto o a metà, challenge);
  senza eventi, perché l'observer del pivot del package aggiungerebbe i POI ai layer di ogni traccia,
  mentre qui i POI dei layer li decide `RecalculateSardegnaSentieriLayersJob` (solo layer delle aree);
  `order` = distanza in metri, quindi le tracce elencano i POI correlati dal più vicino.
- `ResolveSardegnaSentieriRelationsJob` scrive solo i POI correlati fra loro; tolti i suoi test sui
  collegamenti POI-percorsi e quello «fix P1» di `SardegnaSentieriImportServiceTest`.
- `sardegnasentieri:compare-source` non confronta più i legami percorso-POI.
- I POI correlati fra loro restano quelli di Drupal (`ec_poi_related_pois`), «per ora».

## Decisioni

- Solo `api/ec/poi/{id}`: `pois.geojson` resta com'è (servirebbe una modifica nel package, che
  carica sempre `Wm\WmPackage\Models\EcPoi`). La rotta di forestas sostituisce quella del package
  con lo stesso nome e tiene la sua posizione; `.geojson`, download e `api/v2/…` restano del package.
- `related_pois` con la card dei POI del sito (nome, foto, località, quota se c'è): la quota oggi è
  nel testo di `addr_complete` (es. «Tuones (1025 m)»); la geometria dei POI ha quota 0 su tutti.
- Enti dei POI: `enteables` li ha con il tipo `Wm\WmPackage\Models\EcPoi`, come li scrive l'import.
- Slug degli enti in `organizations`, per tracce e POI (richiesta del dev).
- Stato di validazione nella card dei percorsi: fuori, la card resta quella delle tracce.

## Bug trovati

- Nessuno. La colonna `geometry` di `ec_pois` è `NOT NULL`: un POI senza geometria non può esistere,
  il test relativo è stato tolto.

## Follow-up

- Avvisare Rubens: `entities` → `organizations`, `related_pois` e `nearby_tracks` nel dettaglio del POI.
- Dopo il deploy: i POI correlati delle tracce cambiano al primo import (calcolo per vicinanza).
- Raggio da confermare con Forestas (1 km per ora; il wireframe dice 5 km, max 10).
