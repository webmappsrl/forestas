> Ticket: oc:8959

# Dati di Forestas nel dettaglio del POI

Terzo pezzo di oc:8959, dopo le tassonomie (wm-package) e i dati di Forestas nel dettaglio della
traccia (`docs/features/8959-dati-forestas-nel-dettaglio-della-traccia/`). Produce il secondo merge
di oc:8959 in `develop`, d'accordo col dev, e porta con sé la modifica di `entities` →
`organizations` delle tracce (non ancora committata, nel branch del pezzo).

## Cosa cambia

`api/ec/poi/{id}` risponde con un dettaglio costruito da forestas sul modello `App\Models\EcPoi`,
nella stessa logica del dettaglio della traccia: `properties.forestas` costruito in uscita (DB
invariato), chiavi in inglese, niente id di Drupal né chiavi interne dell'import. In più il nome e
le tassonomie nel formato nuovo (`taxonomies`), e i percorsi vicini al POI con la distanza.

I percorsi del POI (`nearby_tracks`) sono quelli di `ec_poi_ec_track`, che dal 12/11 si calcola per
vicinanza: le tracce entro 1 km dal POI (`sardegnasentieri.poi_tracks_radius_meters`), al posto dei
collegamenti scritti su Drupal, come per i sentieri correlati fra tracce (decisione del dev). La
stessa tabella dà i POI correlati delle tracce (`related_pois`), che quindi diventano i POI entro 1 km
(in locale 4.283 collegamenti, 5,6 per traccia, contro i 2.452 di Drupal). I layer non cambiano: i POI
dei layer di questa app li decide `RecalculateSardegnaSentieriLayersJob`, e il calcolo scrive senza
eventi.

`pois.geojson` resta com'è (decisione del dev).

Forma del dettaglio (POI 274, Nuraghe Serbissi):

```json
"properties": {
  "id": 274,
  "name": {"it": "Nuraghe Serbissi"},
  "description": {…}, "addr_complete": "Osini, nuraghe Serbissi", "related_url": {…},
  "taxonomies": {"poi-type": [{"identifier": "archaeological-site", "name": {…}, "icon_name": "txn-ruins"}], "where": […]},
  "taxonomy": {…}, "taxonomyWheres": […],
  "feature_image": {…, "caption": …, "author": …, "credits": …}, "image_gallery": […],
  "forestas": {
    "url": "https://www.sardegnasentieri.it/da-vedere/nuraghe-serbissi-osini",
    "code": "C-521A Nuraghe",
    "created_at": "2013-07-02 18:12:40", "updated_at": "2025-07-28 12:33:33",
    "directions": {"it": "…"},
    "videos": [{"url": "…", "title": "…"}],
    "attachments": [{"url": "…", "title": "…"}],
    "organizations": {"operator": [{"id": 31, "slug": "…", "name": {…}}]},
    "related_pois": [{"id": 212, "name": {"it": "Tuones"}, "feature_image": {…},
                      "addr_complete": "Tuones (1025 m)"}],
    "nearby_tracks": [{"id": 609, "ref": "…", "name": {…}, "route_type": {…},
                       "feature_image": {…}, "distance": null, "duration_forward": null,
                       "distance_from_poi": 28.2}],
    "nearby_tracks_count": 19
  }
}
```

## Perché

Il WordPress di Forestas legge la scheda del POI da `api/ec/poi/{id}` (artifact di analisi,
«Dati dei POI per il sito WordPress»). Oggi il dettaglio non ha il nome, le tassonomie nel formato
nuovo, gli enti, gli allegati, i video e i percorsi vicini, e porta in `properties.forestas` gli
id di Drupal e le chiavi italiane dell'import. Il package costruisce il dettaglio con la propria
classe `Wm\WmPackage\Models\EcPoi` (`EditorialContentController.php:27`), quindi lo shard non ha un
punto in cui agganciarsi senza toccare il package: lo fa sostituendo la rotta.

## Requisiti

- [ ] `api/ec/poi/{id}` (rotta `default.api.ec.poi.json`) risponde da un controller di forestas che
      carica `App\Models\EcPoi`; il package non cambia. Le altre rotte dei POI restano del package.
- [ ] La risposta resta una Feature GeoJSON con le chiavi che il package dà oggi (geometria, `id`,
      `description`, `addr_complete`, `related_url`, `taxonomy`, `taxonomyWheres`, `taxonomy_where`,
      `feature_image`, `image_gallery`, date), più `name` e `taxonomies` (stessa forma di
      `pois.geojson`, `DetailTaxonomiesService`).
- [ ] `properties.forestas` costruito in uscita, chiavi in inglese, vuote escluse: `url`, `code`
      (era `codice`), `created_at`, `updated_at`, `directions` (era `come_arrivare`), `videos` e
      `attachments` (`{url, title}`, indirizzo dello shard, come nelle tracce), `organizations` (come
      nelle tracce: ruolo → `{id, slug, name}`), `related_pois` (da `ec_poi_related_pois`, con i dati della card dei POI: `id`, `name`,
      `feature_image`, `addr_complete`, `elevation` solo se la geometria ha la quota; oggi la quota è
      nel testo di `addr_complete`, es. «Tuones (1025 m)», richiesta del dev del 12/11),
      `nearby_tracks`.
- [ ] Escono dal dettaglio: `zona_geografica`, `source_relations`, `poi_correlati`, `allegati`,
      `video`, `come_arrivare`, `codice` nella forma salvata, `out_source_feature_id`, `layers`.
- [ ] `nearby_tracks`: i percorsi collegati in `ec_poi_ec_track`, dal più vicino, al massimo 10
      (come il wireframe, tav. 7); ogni elemento ha la card delle tracce più `distance_from_poi`
      (metri, dal tratto più vicino); `nearby_tracks_count` dà quanti sono in tutto.
- [ ] `ec_poi_ec_track` calcolata per vicinanza entro 1 km da `CalculatePoiTracksChunkJob`, nello
      stesso batch dei sentieri correlati; ogni blocco riscrive solo i suoi POI in transazione e senza
      eventi; `order` è la distanza in metri. `ResolveSardegnaSentieriRelationsJob` non scrive più i
      collegamenti POI-percorsi di Drupal; `compare-source` non li confronta più.
- [ ] Un test conta le query del dettaglio: le relazioni dei percorsi si caricano insieme.
- [ ] Le parti comuni con il dettaglio della traccia (video, allegati, enti, card del sentiero) si
      riusano, non si copiano.
- [ ] La modifica `entities` → `organizations` delle tracce entra nello stesso branch e merge.

## Rischi

- **Lo stato del percorso non è nella card.** La tavola 7 del wireframe mostra per ogni percorso
  vicino codice, nome, tipo, distanza e **stato**; la card delle tracce non ha lo stato. Il dev ha
  chiesto la forma della prima proposta: lo stato resta fuori finché non si decide di aggiungerlo
  (alla card, quindi anche alle tracce).
- **Cambiano anche i POI correlati delle tracce**: `ec_poi_ec_track` è la stessa riga vista dai due
  lati. Con 1 km le tracce passano da 3,2 a 5,6 POI correlati in media, e si perdono i collegamenti
  della redazione oltre 1 km (un quarto dei 2.452). Scelta del dev, con i numeri (300 m darebbe la
  stessa quantità di Drupal).
- **La rotta sostituita** dipende dall'ordine di registrazione: quella di forestas deve arrivare
  dopo quella del package. Si verifica con un test sulla rotta e con `route:list`.
- **Enti dei POI**: in `enteables` il tipo dei POI è `Wm\WmPackage\Models\EcPoi` (l'import scrive con
  `DB::table`), non `App\Models\EcPoi` come per i media: la relazione va scritta su quel tipo.
- **API di ripiego del package**: `api/ec/poi/{id}.geojson`, `api/v2/ec/poi/{id}` e le altre rotte del
  package continuano a dare il `forestas` salvato. Nessun client usa il dettaglio v2, e il WordPress
  usa `.geojson` solo per disegnare il punto sulla mappa (`[wmwp_map]`, oc:8952): ipotetico.
- **Contratto `organizations`**: sostituisce `entities` già in `develop`; va avvisato Rubens.

## Out of scope

- `pois.geojson` (resta com'è; servirebbe una modifica nel package).
- Stato di validazione nella card dei percorsi vicini (vedi Rischi).
- Quota del POI: la geometria ha quota 0 su tutti i 976 POI.
- Zone geografiche di Drupal (fuori perimetro in oc:8932).
- POI correlati calcolati per vicinanza: per ora quelli importati da Drupal.

## Moduli toccati

forestas:
- `app/Http/Controllers/Api/EcPoiDetailController.php` (nuovo) e registrazione della rotta in
  `AppServiceProvider`.
- `app/Support/ForestasPoiDetail.php` (nuovo); parti comuni estratte da
  `app/Support/ForestasTrackDetail.php`.
- `app/Models/EcPoi.php`: relazioni `nearbyTracks()` (su `ec_poi_ec_track`) e `enti()`.
- `app/Jobs/Import/CalculatePoiTracksChunkJob.php` (nuovo), `CalculateNearbyTracksJob`,
  `ResolveSardegnaSentieriRelationsJob`, `CompareSardegnaSentieriSourceCommand`, `config/sardegnasentieri.php`.
- Test in `tests/Feature/EcPoi/`.
- Documentazione: `docs/knowledge/` (pagina del dettaglio), `notes.md` del pezzo, `CLAUDE.md`.
