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

I percorsi vicini si calcolano dalla geometria entro 5 km (`sardegnasentieri.poi_nearby_tracks_radius_meters`,
separato dai 50 m dei sentieri correlati delle tracce; decisione del dev del 12/11, come il wireframe)
e si salvano in una tabella con la
distanza, calcolata nello stesso batch dei sentieri correlati a fine import.

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
    "related_pois": [168, 278],
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
      nelle tracce: ruolo → `{id, slug, name}`), `related_pois` (id dello shard da `ec_poi_related_pois`),
      `nearby_tracks`.
- [ ] Escono dal dettaglio: `zona_geografica`, `source_relations`, `poi_correlati`, `allegati`,
      `video`, `come_arrivare`, `codice` nella forma salvata, `out_source_feature_id`, `layers`.
- [ ] `nearby_tracks`: i 10 percorsi più vicini entro 5 km (`sardegnasentieri.poi_nearby_tracks_radius_meters`
      e `poi_nearby_tracks_limit`, come il wireframe, tav. 7), dal più vicino; `nearby_tracks_count` dà
      quanti sono in tutto entro 5 km (il «Percorsi entro 5 km» del riepilogo). La tabella li salva tutti;
      ogni elemento ha la card delle tracce (`id`, `ref`, `name`, `route_type`, `feature_image`,
      `distance`, `duration_forward`) più `distance_from_poi` (metri, dal tratto più vicino).
- [ ] Tabella `ec_poi_nearby_tracks` (`ec_poi_id`, `ec_track_id`, `distance`), riempita da blocchi
      nello stesso batch di `CalculateNearbyTracksJob`; la rigenerazione nel `finally` resta dopo
      tutti i blocchi. **Niente svuotamento globale**: ogni blocco cancella e riscrive solo le righe
      dei suoi POI in una transazione, perché il dettaglio legge la tabella dal vivo e non deve mai
      vederla vuota o a metà (challenge, decisione del dev). Restano fuori i POI senza geometria.
- [ ] Un test conta le query del dettaglio: le relazioni dei percorsi vicini si caricano insieme.
- [ ] Le parti comuni con il dettaglio della traccia (video, allegati, enti, card del sentiero) si
      riusano, non si copiano.
- [ ] La modifica `entities` → `organizations` delle tracce entra nello stesso branch e merge.

## Rischi

- **Lo stato del percorso non è nella card.** La tavola 7 del wireframe mostra per ogni percorso
  vicino codice, nome, tipo, distanza e **stato**; la card delle tracce non ha lo stato. Il dev ha
  chiesto la forma della prima proposta: lo stato resta fuori finché non si decide di aggiungerlo
  (alla card, quindi anche alle tracce).
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
- `app/Models/EcPoi.php`: relazioni `nearbyTracks()` e `enti()`.
- `database/migrations/…_create_ec_poi_nearby_tracks_table.php` (nuovo).
- `app/Jobs/Import/CalculateNearbyTracksJob.php` e un job per i blocchi dei POI.
- Test in `tests/Feature/EcPoi/` e `tests/Feature/Import/NearbyTracksTest.php`.
- Documentazione: `docs/knowledge/` (pagina del dettaglio), `notes.md` del pezzo, `CLAUDE.md`.
