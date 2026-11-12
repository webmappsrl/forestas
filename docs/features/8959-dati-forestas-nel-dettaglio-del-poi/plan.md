> Ticket: oc:8959

# Dati di Forestas nel dettaglio del POI — piano

**Goal:** `api/ec/poi/{id}` risponde con il dettaglio di forestas (nome, `taxonomies`,
`properties.forestas` pulito, percorsi vicini con la distanza), senza modifiche al package.

**Architettura:** una rotta di forestas sostituisce `default.api.ec.poi.json` e carica
`App\Models\EcPoi`; `App\Support\ForestasPoiDetail` costruisce `properties.forestas` in uscita come
`ForestasTrackDetail`, con le parti comuni estratte. I percorsi vicini stanno in
`ec_poi_nearby_tracks`, scritta per blocco nello stesso batch dei sentieri correlati.

**Stack:** Laravel 12, PostGIS (`geography`), Pest su `forestas_testing`.

**Spec:** `docs/features/8959-dati-forestas-nel-dettaglio-del-poi/overview.md`

## Global Constraints

- Nessuna modifica a `wm-package/`.
- Identificatori in inglese, commenti e documentazione in italiano.
- Test solo su `forestas_testing` (`.claude/rules/test.md`); niente commit, `git add` o push durante
  l'esecuzione: li fa il dev dopo l'approvazione.
- Distanze in metri da colonne `geography` (cast esplicito `::geography`, mai `::geometry`).
- `pois.geojson` non cambia.

## Review Focus

- POI senza geometria: escluso dal calcolo, dettaglio senza `nearby_tracks`, nessun errore.
- POI con un solo ruolo o senza enti: `organizations` assente, non un oggetto vuoto.
- Rilancio del batch mentre un blocco POI scrive: le righe degli altri POI restano.
- `come_arrivare` salvato `null` o non array: `directions` assente, nessuna eccezione.
- Rotta: `api/ec/poi/{id}` risolve al controller di forestas anche con `route:cache`.

---

### Task 1: parti comuni del dettaglio

**Files:**
- Create: `app/Support/ForestasDetailParts.php`
- Modify: `app/Support/ForestasTrackDetail.php`

**Interfaces — Produces:**
- `ForestasDetailParts::remoteVideos(mixed $urls): array`
- `ForestasDetailParts::files(HasMedia&Model $model, string $collection): array` (`{url, title}`)
- `ForestasDetailParts::organizations(iterable $entes): array` (ruolo → `{id, name}`, con
  `ORGANIZATION_ROLES`; riceve gli enti con il pivot `ruolo`)
- `ForestasDetailParts::trackCard(EcTrack $track): array` (la card di `nearby_tracks` delle tracce)

- [ ] Spostare i metodi da `ForestasTrackDetail` senza cambiarne il comportamento; `ForestasTrackDetail`
      li chiama.
- [ ] Run: `vendor/bin/pest tests/Feature/EcTrack` — Expected: tutti verdi, nessun test cambiato.
- [ ] Commit (istruzione per il dev): `refactor(oc:8959): parti comuni del dettaglio di tracce e POI`

### Task 2: tabella dei percorsi vicini ai POI

**Files:**
- Create: `database/migrations/2026_11_12_000000_create_ec_poi_nearby_tracks_table.php`
- Create: `app/Jobs/Import/CalculatePoiNearbyTracksChunkJob.php`
- Modify: `app/Jobs/Import/CalculateNearbyTracksJob.php`, `app/Models/EcPoi.php`
- Test: `tests/Feature/Import/NearbyTracksTest.php`

**Interfaces — Produces:**
- tabella `ec_poi_nearby_tracks` (`ec_poi_id`, `ec_track_id`, `distance` float, chiave primaria
  sulla coppia, FK in cascata su entrambi)
- `CalculatePoiNearbyTracksChunkJob(list<int> $poiIds)` (Batchable, coda di import,
  `WithoutOverlapping` per primo id)
- `App\Models\EcPoi::nearbyTracks(): BelongsToMany` (pivot `distance`, ordinato per distanza)

- [ ] Test che falliscono: un POI a ~30 m da una traccia ha la riga con la distanza; uno a ~6 km no;
      il blocco riscrive solo i suoi POI (le righe di un altro POI restano); POI senza geometria
      escluso; `CalculateNearbyTracksJob` accoda nello stesso batch anche i blocchi dei POI e non
      svuota `ec_poi_nearby_tracks`.
- [ ] Implementare: nel blocco, per ogni POI `ST_DWithin`/`ST_Distance` sulle tracce della stessa app
      entro `sardegnasentieri.poi_nearby_tracks_radius_meters` (5000); poi in una transazione `delete` delle righe
      dei POI del blocco e `insert` delle nuove.
- [ ] Run: `vendor/bin/pest tests/Feature/Import/NearbyTracksTest.php` — Expected: verdi.
- [ ] Commit: `feat(oc:8959): percorsi vicini ai POI calcolati nel batch dei correlati`

### Task 3: dettaglio del POI

**Files:**
- Create: `app/Support/ForestasPoiDetail.php`, `app/Http/Controllers/Api/EcPoiDetailController.php`
- Modify: `app/Models/EcPoi.php` (`enti()`), `app/Providers/AppServiceProvider.php` (rotta)
- Test: `tests/Feature/EcPoi/PoiDetailForestasTest.php`

**Interfaces — Consumes:** Task 1, `EcPoi::nearbyTracks()` del Task 2.
**Produces:** `ForestasPoiDetail::build(EcPoi $poi): array`; rotta `default.api.ec.poi.json`.

- [ ] Test che falliscono:
  - la rotta `default.api.ec.poi.json` risolve a `EcPoiDetailController`;
  - `GET api/ec/poi/{id}` dà `name`, `taxonomies` e le chiavi del package di oggi;
  - `properties.forestas` ha `url`, `code`, `created_at`, `updated_at`, `directions`, `videos`,
    `attachments`, `organizations` (ruolo `operator`), `related_pois` (id dello shard),
    `nearby_tracks` (card più `distance_from_poi`, ordinati, al massimo `poi_nearby_tracks_limit` = 10)
    e `nearby_tracks_count` (tutti quelli entro il raggio);
  - non ci sono `zona_geografica`, `source_relations`, `poi_correlati`, `allegati`, `video`,
    `come_arrivare`, `codice`, `out_source_feature_id`, `layers`; il DB non cambia;
  - chiavi vuote assenti; `come_arrivare` `null` non rompe;
  - il numero di query non cresce con il numero di percorsi vicini.
- [ ] Implementare:
  - `EcPoi::enti()` su `enteables` con `enteable_type` = `Wm\WmPackage\Models\EcPoi` (come lo
    scrive l'import), pivot `ruolo`;
  - controller: `App\Models\EcPoi::findOrFail($id)`, `getGeojson()`, poi `name`,
    `taxonomies` (`DetailTaxonomiesService::buildForJson($poi, 'EcPoi', app_id)`), `forestas` da
    `ForestasPoiDetail::build()`, tolti `out_source_feature_id` e `layers`;
  - rotta registrata in `AppServiceProvider::boot()` con lo stesso URI e nome, middleware `api`.
- [ ] Run: `vendor/bin/pest tests/Feature/EcPoi` e `php artisan route:list --path=api/ec/poi` —
      Expected: verdi; `api/ec/poi/{id}` → `EcPoiDetailController`.
- [ ] Commit: `feat(oc:8959): dati di Forestas nel dettaglio del POI`

### Task 4: verifica e documentazione

- [ ] Suite completa e PHPStan; `api/ec/poi/274` in locale controllato a mano.
- [ ] Documentazione: pagina di conoscenza (dettaglio della traccia allargata ai POI o pagina nuova),
      `notes.md` del pezzo, riga in `CLAUDE.md`; la modifica `organizations` è già documentata.
- [ ] Commit: `docs(oc:8959): dettaglio del POI`
